<?php

namespace AuditStash\Shell\Task;

use Cake\Console\Shell;
use Cake\Datasource\ConnectionManager;
use Cake\I18n\Time;
use Cake\Utility\Inflector;
use Elastica\Document;

/**
 * Imports audit logs from the legacy audit logs tables into elastic search.
 */
class ElasticImportTask extends Shell
{
    /**
     * {@inheritdoc}
     */
    public function getOptionParser()
    {
        return parent::getOptionParser()
            ->setDescription('Imports audit logs from the legacy audit logs tables into elastic search')
            ->addOption('from', [
                'short' => 'f',
                'help' => 'The date from which to start importing audit logs',
                'default' => 'now'
            ])
            ->addOption('until', [
                'short' => 'u',
                'help' => 'The date in which to stop importing audit logs',
                'default' => 'now'
            ])->addOption('models', [
                'short' => 'm',
                'help' => 'A comma separated list of model names to import'
            ])
            ->addOption('exclude-models', [
                'short' => 'e',
                'help' => 'A comma separated list of model names to skip importing'
            ])
            ->addOption('type-map', [
                'short' => 't',
                'help' => 'A comma separated list of model:type pairs (for example ParentCategory:categories)'
            ])
            ->addOption('extra-meta', [
                'short' => 'a',
                'help' => 'A comma separated list of key:value pairs to store in meta (for example app_name:frontend)'
            ]);
    }

    /**
     * Copies for the audits and audits_logs table into the elastic search storage.
     *
     * @return bool
     */
    public function main()
    {
        $table = $this->loadModel('Audits');
        $table->hasMany('AuditDeltas');
        $table->schema()->columnType('created', 'text');
        $map = [];
        $meta = [];

        $featureList = function ($element) {
            [$k, $v] = explode(':', $element);
            yield $k => $v;
        };

        if (!empty($this->params['type-map'])) {
            $map = explode(',', (string) $this->params['type-map']);
            $map = collection($map)->unfold($featureList)->toArray();
        }

        if (!empty($this->params['extra-meta'])) {
            $meta = explode(',', (string) $this->params['extra-meta']);
            $meta = collection($meta)->unfold($featureList)->toArray();
        }

        $from = (new Time($this->params['from']))->modify('midnight');
        $until = (new Time($this->params['until']))->modify('23:59:59');

        $currentId = null;
        $buffer = new \SplQueue();
        $buffer->setIteratorMode(\SplDoublyLinkedList::IT_MODE_DELETE);
        $queue = new \SplQueue();
        $queue->setIteratorMode(\SplDoublyLinkedList::IT_MODE_DELETE);
        $index = ConnectionManager::get('auditlog_elastic')->getConfig('index');

        $eventsFormatter = fn($audit) => $this->eventFormatter($audit, $index, $meta);
        $changesExtractor = $this->changesExtractor(...);

        $query = $table->find()
            ->where(fn($exp) => $exp->between('Audits.created', $from, $until, 'datetime'))
            ->where(function ($exp) {
                if (!empty($this->params['exclude-models'])) {
                    $exp->notIn('Audits.model', explode(',', (string) $this->params['exclude-models']));
                }

                if (!empty($this->params['models'])) {
                    $exp->in('Audits.model', explode(',', (string) $this->params['models']));
                }
                return $exp;
            })
            ->matching('AuditDeltas')
            ->order(['Audits.created', 'AuditDeltas.audit_id'])
            ->bufferResults(false)
            ->hydrate(false)
            ->unfold(function ($audit) use ($buffer, &$currentId) {
                if ($currentId && $currentId !== $audit['id']) {
                    yield collection($buffer)->toList();
                }
                $currentId = $audit['id'];
                $buffer->enqueue($audit);
            })
            ->map($changesExtractor)
            ->map($eventsFormatter)
            ->unfold(function ($audit) use ($queue) {
                $queue->enqueue($audit);

                if ($queue->count() >= 50) {
                    yield collection($queue)->toList();
                }
            });

        $query->each($this->persistBulk(...));

        // There are probably some un-yielded results, let's flush them
        $rest = collection(count($buffer) ? [collection($buffer)->toList()] : [])
            ->map($changesExtractor)
            ->map($eventsFormatter)
            ->append($queue);

        $this->persistBulk($rest->toList());

        return true;
    }

    /**
     * Converts the string data from HABTM reference into an array of integers.
     *
     * @param mixed $value The value to convert
     * @param string $key The field name where the data was stored
     * @return mixed
     */
    public function habtmFormatter(mixed $value, $key)
    {
        if (empty($key) || !ctype_upper($key[0])) {
            return $value;
        }

        if (isset($value[$key])) {
            // $article[Tag][Tag]
            $value = $value[$key];
        }

        if (is_array($value)) {
            return $value;
        }

        $list = explode(',', (string) $value);

        if (empty($list)) {
            return [];
        }

        return array_map('intval', $list);
    }

    /**
     * Converts a bad mysql 0000-00-00 date to nul.
     *
     * @param mixed $value The value to convert
     * @return mixed
     */
    public function allBallsRemover(mixed $value)
    {
        if (is_string($value) && str_starts_with($value, '0000-00-00')) {
            return;
        }
        return $value;
    }

    /**
     * Converts a group of related audit logs into a single one with the
     * original and changed keys set.
     *
     * @param array $audits The list of audit logs to compile into a single one
     * @return array
     */
    public function changesExtractor($audits)
    {
        $suffix = isset($audits[0]['_matchingData']['AuditDeltas'][0]) ? '.{*}' : '';
        $changes = collection($audits)
            ->extract('_matchingData.AuditDeltas' . $suffix)
            ->indexBy('property_name')
            ->toArray();

        $audit = $audits[0];
        unset($audit['_matchingData']);

        $audit['original'] = collection($changes)
            ->map(fn($c) => $c['old_value'])
            ->map($this->habtmFormatter(...))
            ->map($this->allBallsRemover(...))
            ->toArray();
        $audit['changed'] = collection($changes)
            ->map(fn($c) => $c['new_value'])
            ->map($this->habtmFormatter(...))
            ->map($this->allBallsRemover(...))
            ->toArray();

        return $audit;
    }

    /**
     * Converts the single audit log event array into a Elastica\Document so it can be stored.
     *
     * @param array $audit The audit log information
     * @param string $index The name of the index where the event should be stored
     * @param array $meta The meta information to append to the meta array
     * @return Elastica\Document
     */
    public function eventFormatter($audit, $index, $meta = [])
    {
        $map = [];
        $data = [
            '@timestamp' => $audit['created'],
            'transaction' => $audit['id'],
            'type' => $audit['event'] === 'EDIT' ? 'update' : strtolower((string) $audit['event']),
            'primary_key' => $audit['entity_id'],
            'original' => $audit['original'],
            'changed' => $audit['changed'],
            'meta' => $meta + [
                'ip' => $audit['source_ip'],
                'url' => $audit['source_url'],
                'user' => $audit['source_id'],
            ]
        ];

        $index = sprintf($index, \DateTime::createFromFormat('Y-m-d H:i:s', $audit['created'])->format('-Y.m.d'));
        $type = $map[$audit['model']] ?? Inflector::tableize($audit['model']);
        return new Document($audit['id'], $data, $type, $index);
    }

    /**
     * Persists the array of passed documents into elastic search.
     *
     * @param array $documents List of Elastica\Document objects to be persisted
     * @return void
     */
    public function persistBulk($documents)
    {
        if (empty($documents)) {
            $this->log('No more documents to index', 'info');
            return;
        }
        $this->log(sprintf('Indexing %d documents', count($documents)), 'info');
        ConnectionManager::get('auditlog_elastic')->addDocuments($documents);
    }
}
