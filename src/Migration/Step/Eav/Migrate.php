<?php
/**
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Migration\Step\Eav;

use Migration\MapReaderInterface;
use Migration\MapReader\MapReaderEav;
use Migration\ProgressBar;
use Migration\Resource\Destination;
use Migration\Resource\Record;
use Migration\Resource\Document;
use Migration\Resource\RecordFactory;
use Migration\Resource\Source;

/**
 * Class Migrate
 * @codeCoverageIgnoreStart
 */
class Migrate
{
    /**
     * @var array;
     */
    protected $newAttributes;

    /**
     * @var array;
     */
    protected $newAttributeSets;

    /**
     * @var array;
     */
    protected $newAttributeGroups;

    /**
     * @var array;
     */
    protected $destAttributeOldNewMap;

    /**
     * @var array;
     */
    protected $destAttributeSetsOldNewMap;

    /**
     * @var array;
     */
    protected $destAttributeGroupsOldNewMap;

    /**
     * @var Helper
     */
    protected $helper;

    /**
     * @var Source
     */
    protected $source;

    /**
     * @var Destination
     */
    protected $destination;

    /**
     * @var MapReaderEav
     */
    protected $map;

    /**
     * @var RecordFactory
     */
    protected $factory;

    /**
     * @var InitialData
     */
    protected $initialData;

    /**
     * @var ProgressBar
     */
    protected $progress;

    /**
     * @var \Migration\ListsReader
     */
    protected $readerSimple;

    /**
     * @param Source $source
     * @param Destination $destination
     * @param MapReaderEav $mapReader
     * @param \Migration\ListsReaderFactory $listsReaderFactory
     * @param Helper $helper
     * @param RecordFactory $factory
     * @param InitialData $initialData
     * @param ProgressBar $progress
     */
    public function __construct(
        Source $source,
        Destination $destination,
        MapReaderEav $mapReader,
        \Migration\ListsReaderFactory $listsReaderFactory,
        Helper $helper,
        RecordFactory $factory,
        InitialData $initialData,
        ProgressBar $progress
    ) {
        $this->source = $source;
        $this->destination = $destination;
        $this->map = $mapReader;
        $this->readerSimple = $listsReaderFactory->create(['optionName' => 'eav_list_file']);
        $this->helper = $helper;
        $this->factory = $factory;
        $this->initialData = $initialData;
        $this->progress = $progress;
    }

    /**
     * Entry point. Run migration of EAV structure.
     * @return bool
     */
    public function perform()
    {
        $this->progress->start($this->getIterationsCount());
        $this->migrateAttributeSetsAndGroups();
        $this->migrateAttributes();
        $this->migrateEntityAttributes();
        $this->migrateMappedTables();
        $this->progress->finish();
        return true;
    }

    /**
     * Migrate eav_attribute_set and eav_attribute_group
     * @return void
     */
    protected function migrateAttributeSetsAndGroups()
    {
        foreach (['eav_attribute_set', 'eav_attribute_group'] as $documentName) {
            $this->progress->advance();
            $sourceDocument = $this->source->getDocument($documentName);
            $destinationDocument = $this->destination->getDocument(
                $this->map->getDocumentMap($documentName, MapReaderInterface::TYPE_SOURCE)
            );

            $this->destination->backupDocument($destinationDocument->getName());

            $sourceRecords = $this->source->getRecords($documentName, 0, $this->source->getRecordsCount($documentName));
            $recordsToSave = $destinationDocument->getRecords();
            foreach ($sourceRecords as $recordData) {
                $sourceRecord = $this->factory->create(['document' => $sourceDocument, 'data' => $recordData]);
                $destinationRecord = $this->factory->create(['document' => $destinationDocument]);
                $this->helper->getRecordTransformer($sourceDocument, $destinationDocument)
                    ->transform($sourceRecord, $destinationRecord);
                $recordsToSave->addRecord($destinationRecord);
            }

            if ($documentName == 'eav_attribute_set') {
                foreach ($this->initialData->getAttributeSets('dest') as $record) {
                    $record['attribute_set_id'] = null;
                    $destinationRecord = $this->factory->create(
                        [
                            'document' => $destinationDocument,
                            'data' => $record
                        ]
                    );
                    $recordsToSave->addRecord($destinationRecord);
                }
            }

            if ($documentName == 'eav_attribute_group') {
                foreach ($this->initialData->getAttributeGroups('dest') as $record) {
                    $oldAttributeSet = $this->initialData->getAttributeSets('dest')[$record['attribute_set_id']];
                    $newAttributeSet = $this->newAttributeSets[
                        $oldAttributeSet['entity_type_id'] . '-' . $oldAttributeSet['attribute_set_name']
                    ];
                    $record['attribute_set_id'] = $newAttributeSet['attribute_set_id'];

                    $record['attribute_group_id'] = null;
                    $destinationRecord = $this->factory->create(
                        [
                            'document' => $destinationDocument,
                            'data' => $record
                        ]
                    );
                    $recordsToSave->addRecord($destinationRecord);
                }
            }

            $this->saveRecords($destinationDocument, $recordsToSave);
            if ($documentName == 'eav_attribute_set') {
                $this->loadNewAttributeSets();
            }
            if ($documentName == 'eav_attribute_group') {
                $this->loadNewAttributeGroups();
            }
        }
    }

    /**
     * Migrate eav_attribute
     * @return void
     */
    protected function migrateAttributes()
    {
        $this->progress->advance();
        $sourceDocName = 'eav_attribute';
        $sourceDocument = $this->source->getDocument($sourceDocName);
        $destinationDocument = $this->destination->getDocument(
            $this->map->getDocumentMap($sourceDocName, MapReaderInterface::TYPE_SOURCE)
        );
        $this->destination->backupDocument($destinationDocument->getName());
        $sourceRecords = $this->source->getRecords($sourceDocName, 0, $this->source->getRecordsCount($sourceDocName));
        $destinationRecords = $this->initialData->getAttributes('dest');

        $recordsToSave = $destinationDocument->getRecords();
        foreach ($sourceRecords as $sourceRecordData) {
            /** @var Record $sourceRecord */
            $sourceRecord = $this->factory->create(['document' => $sourceDocument, 'data' => $sourceRecordData]);
            /** @var Record $destinationRecord */
            $destinationRecord = $this->factory->create(['document' => $destinationDocument]);

            $mappingValue = $this->getMappingValue($sourceRecord, ['entity_type_id', 'attribute_code']);
            if (isset($destinationRecords[$mappingValue])) {
                $destinationRecordData = $destinationRecords[$mappingValue];
                unset($destinationRecords[$mappingValue]);
            } else {
                $destinationRecordData = array_fill_keys($destinationRecord->getFields(), null);
            }
            $destinationRecord->setData($destinationRecordData);

            $this->helper->getRecordTransformer($sourceDocument, $destinationDocument)
                ->transform($sourceRecord, $destinationRecord);
            $recordsToSave->addRecord($destinationRecord);
        }

        foreach ($destinationRecords as $record) {
            /** @var Record $destinationRecord */
            $destinationRecord = $this->factory->create(['document' => $destinationDocument, 'data' => $record]);
            $destinationRecord->setValue('attribute_id', null);
            $recordsToSave->addRecord($destinationRecord);
        }

        $this->saveRecords($destinationDocument, $recordsToSave);
        $this->loadNewAttributes();
    }

    /**
     * Migrate eav_entity_attributes
     *
     * @return void
     */
    protected function migrateEntityAttributes()
    {
        $this->progress->advance();
        $sourceDocName = 'eav_entity_attribute';
        $sourceDocument = $this->source->getDocument($sourceDocName);
        $destinationDocument = $this->destination->getDocument(
            $this->map->getDocumentMap($sourceDocName, MapReaderInterface::TYPE_SOURCE)
        );
        $this->destination->backupDocument($destinationDocument->getName());
        $recordsToSave = $destinationDocument->getRecords();
        foreach ($this->helper->getSourceRecords($sourceDocName) as $sourceRecordData) {
            $sourceRecord = $this->factory->create([
                'document' => $sourceDocument,
                'data' => $sourceRecordData
            ]);
            $destinationRecord = $this->factory->create(['document' => $destinationDocument]);
            $this->helper->getRecordTransformer($sourceDocument, $destinationDocument)
                ->transform($sourceRecord, $destinationRecord);
            $recordsToSave->addRecord($destinationRecord);
        }

        foreach ($this->helper->getDestinationRecords('eav_entity_attribute') as $record) {
            if (!isset($this->destAttributeOldNewMap[$record['attribute_id']])
                || !isset($this->destAttributeSetsOldNewMap[$record['attribute_set_id']])
                || !isset($this->destAttributeGroupsOldNewMap[$record['attribute_group_id']])
            ) {
                continue;
            }
            $record['attribute_id'] = $this->destAttributeOldNewMap[$record['attribute_id']];
            $record['attribute_set_id'] = $this->destAttributeSetsOldNewMap[$record['attribute_set_id']];
            $record['attribute_group_id'] = $this->destAttributeGroupsOldNewMap[$record['attribute_group_id']];

            $record['entity_attribute_id'] = null;
            $destinationRecord = $this->factory->create(['document' => $destinationDocument, 'data' => $record]);
            $recordsToSave->addRecord($destinationRecord);
        }

        $this->saveRecords($destinationDocument, $recordsToSave);
    }

    /**
     * Migrate EAV tables which in result must have all unique records from both source and destination documents
     * @return void
     */
    protected function migrateMappedTables()
    {
        $documents = $this->getDocuments();

        foreach ($documents as $documentName => $mappingFields) {
            $this->progress->advance();
            $sourceDocument = $this->source->getDocument($documentName);
            $destinationDocument = $this->destination->getDocument(
                $this->map->getDocumentMap($documentName, MapReaderInterface::TYPE_SOURCE)
            );
            $this->destination->backupDocument($destinationDocument->getName());
            $destinationRecords = $this->helper->getDestinationRecords($documentName, $mappingFields);
            $recordsToSave = $destinationDocument->getRecords();
            foreach ($this->helper->getSourceRecords($documentName) as $recordData) {
                /** @var Record $sourceRecord */
                $sourceRecord = $this->factory->create(['document' => $sourceDocument, 'data' => $recordData]);
                /** @var Record $destinationRecord */
                $destinationRecord = $this->factory->create(['document' => $destinationDocument]);

                $mappingValue = $this->getMappingValue($sourceRecord, $mappingFields);
                if (isset($destinationRecords[$mappingValue])) {
                    $destinationRecordData = $destinationRecords[$mappingValue];
                    unset($destinationRecords[$mappingValue]);
                } else {
                    $destinationRecordData = array_fill_keys($destinationRecord->getFields(), null);
                }
                $destinationRecord->setData($destinationRecordData);

                $this->helper->getRecordTransformer($sourceDocument, $destinationDocument)
                    ->transform($sourceRecord, $destinationRecord);

                if ($documentName == 'eav_entity_type') {
                    $oldAttributeSetValue = $destinationRecord->getValue('default_attribute_set_id');
                    if (isset($this->destAttributeSetsOldNewMap[$oldAttributeSetValue])) {
                        $destinationRecord->setValue(
                            'default_attribute_set_id',
                            $this->destAttributeSetsOldNewMap[$oldAttributeSetValue]
                        );
                    }
                }

                $recordsToSave->addRecord($destinationRecord);
            }

            if ($mappingFields) {
                foreach ($destinationRecords as $record) {
                    $destinationRecord = $this->factory->create([
                        'document' => $destinationDocument,
                        'data' => $record
                    ]);
                    if (isset($record['attribute_id'])
                        && isset($this->destAttributeOldNewMap[$record['attribute_id']])
                    ) {
                        $destinationRecord->setValue(
                            'attribute_id',
                            $this->destAttributeOldNewMap[$record['attribute_id']]
                        );
                    }
                    $recordsToSave->addRecord($destinationRecord);
                }
            }

            $this->saveRecords($destinationDocument, $recordsToSave);
        }
    }

    /**
     * @return array
     */
    protected function getDocuments()
    {
        $result = [];
        $documents = $this->readerSimple->getList('documents');
        foreach ($documents as $document) {
            $fieldsMap = $this->readerSimple->getList($document);
            if (!empty($fieldsMap)) {
                $result[$document] = $fieldsMap;
            }
        }
        return $result;
    }

    /**
     * @param Document $document
     * @param Record\Collection $recordsToSave
     * @return void
     */
    protected function saveRecords(Document $document, Record\Collection $recordsToSave)
    {
        $this->destination->clearDocument($document->getName());
        $this->destination->saveRecords($document->getName(), $recordsToSave);
    }

    /**
     * @param Record $sourceRecord
     * @param array $keyFields
     * @return string
     */
    protected function getMappingValue(Record $sourceRecord, $keyFields)
    {
        $value = [];
        foreach ($keyFields as $field) {
            switch ($field) {
                case 'attribute_id':
                    $value[] =  $this->getDestinationAttributeId($sourceRecord->getValue($field));
                    break;
                default:
                    $value[] = $sourceRecord->getValue($field);
                    break;
            }
        }
        return implode('-', $value);
    }

    /**
     * Load migrated attribute sets data
     * @return void
     */
    protected function loadNewAttributeSets()
    {
        $this->newAttributeSets = $this->helper->getDestinationRecords(
            'eav_attribute_set',
            ['entity_type_id', 'attribute_set_name']
        );
        foreach ($this->initialData->getAttributeSets('dest') as $attributeSetId => $record) {
            $newAttributeSet = $this->newAttributeSets[$record['entity_type_id'] . '-' . $record['attribute_set_name']];
            $this->destAttributeSetsOldNewMap[$attributeSetId] = $newAttributeSet['attribute_set_id'];
        }
    }

    /**
     * Load migrated attribute groups data
     * @return void
     */
    protected function loadNewAttributeGroups()
    {
        $this->newAttributeGroups = $this->helper->getDestinationRecords(
            'eav_attribute_group',
            ['attribute_set_id', 'attribute_group_name']
        );
        foreach ($this->initialData->getAttributeGroups('dest') as $record) {
            $newKey = $this->destAttributeSetsOldNewMap[$record['attribute_set_id']] . '-'
                . $record['attribute_group_name'];
            $newAttributeGroup = $this->newAttributeGroups[$newKey];
            $this->destAttributeGroupsOldNewMap[
                $record['attribute_group_id']] = $newAttributeGroup['attribute_group_id'
            ];
        }
    }

    /**
     * Load migrated attributes data
     * @return array
     */
    protected function loadNewAttributes()
    {
        $this->newAttributes = $this->helper->getDestinationRecords(
            'eav_attribute',
            ['entity_type_id', 'attribute_code']
        );
        foreach ($this->initialData->getAttributes('dest') as $key => $attributeData) {
            $this->destAttributeOldNewMap[$attributeData['attribute_id']] = $this->newAttributes[$key]['attribute_id'];
        }

        return $this->newAttributes;
    }

    /**
     * @param int $sourceAttributeId
     * @return mixed
     */
    protected function getDestinationAttributeId($sourceAttributeId)
    {
        $id = null;
        $key = null;
        if (isset($this->initialData->getAttributes('source')[$sourceAttributeId])) {
            $key = $this->initialData->getAttributes('source')[$sourceAttributeId]['entity_type_id'] . '-'
                . $this->initialData->getAttributes('source')[$sourceAttributeId]['attribute_code'];
        }

        if ($key && isset($this->initialData->getAttributes('dest')[$key])) {
            $id = $this->initialData->getAttributes('dest')[$key]['attribute_id'];
        }

        return $id;
    }

    /**
     * @return int
     */
    public function getIterationsCount()
    {
        return count($this->readerSimple->getList('documents'));
    }

    /**
     * Rollback backuped documents
     * @return void
     */
    public function rollback()
    {
        foreach ($this->readerSimple->getList('documents') as $documentName) {
            $destinationDocument = $this->destination->getDocument(
                $this->map->getDocumentMap($documentName, MapReaderInterface::TYPE_SOURCE)
            );
            $this->destination->rollbackDocument($destinationDocument->getName());
        }
    }

    /**
     * Delete backuped tables
     * @return void
     */
    public function deleteBackups()
    {
        foreach ($this->readerSimple->getList('documents') as $documentName) {
            $documentName = $this->map->getDocumentMap($documentName, MapReaderInterface::TYPE_SOURCE);
            if ($documentName) {
                $this->destination->deleteDocumentBackup($documentName);
            }
        }
    }
    // @codeCoverageIgnoreEnd
}