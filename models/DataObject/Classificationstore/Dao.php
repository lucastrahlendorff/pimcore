<?php

/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Commercial License (PCL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 *  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 *  @license    http://www.pimcore.org/license     GPLv3 and PCL
 */

namespace Pimcore\Model\DataObject\Classificationstore;

use Doctrine\DBAL\ArrayParameterType;
use Pimcore\Db\Helper;
use Pimcore\Element\MarshallerService;
use Pimcore\Logger;
use Pimcore\Model;
use Pimcore\Model\DataObject;
use Pimcore\Normalizer\NormalizerInterface;

/**
 * @internal
 *
 * @property \Pimcore\Model\DataObject\Classificationstore $model
 */
class Dao extends Model\Dao\AbstractDao
{
    use DataObject\ClassDefinition\Helper\Dao;

    protected array $tableDefinitions = [];

    public function getDataTableName(): string
    {
        return 'object_classificationstore_data_' . $this->model->getClass()->getId();
    }

    public function getGroupsTableName(): string
    {
        return 'object_classificationstore_groups_' . $this->model->getClass()->getId();
    }

    /**
     * @throws \Exception
     */
    public function save(): void
    {
        if (!DataObject::isDirtyDetectionDisabled() && !$this->model->hasDirtyFields()) {
            return;
        }
        $object = $this->model->getObject();
        $objectId = $object->getId();
        $dataTable = $this->getDataTableName();
        $fieldname = $this->model->getFieldname();

        $groupsToKeep = [];
        $collectionToKeep = [];
        $systemRowToDelete = [];

        $items = $this->model->getItems();
        $activeGroups = $this->model->getActiveGroups();

        $collectionMapping = $this->model->getGroupCollectionMappings();

        $groupsTable = $this->getGroupsTableName();

        foreach ($activeGroups as $activeGroupId => $enabled) {
            if ($enabled) {
                $data = [
                    'id' => $objectId,
                    'groupId' => $activeGroupId,
                    'fieldname' => $fieldname,
                ];
                Helper::upsert($this->db, $groupsTable, $data, $this->getPrimaryKey($groupsTable));
                $groupsToKeep[] = $activeGroupId;
            }
        }

        foreach ($items as $groupId => $group) {
            foreach ($group as $keyId => $keyData) {
                if (!isset($activeGroups[$groupId])) {
                    continue;
                }
                $keyConfig = DefinitionCache::get($keyId);
                $fd = Service::getFieldDefinitionFromKeyConfig($keyConfig);

                foreach ($keyData as $language => $value) {
                    $collectionId = $collectionMapping[$groupId] ?? null;
                    $data = [
                        'id' => $objectId,
                        'collectionId' => $collectionId,
                        'groupId' => $groupId,
                        'keyId' => $keyId,
                        'fieldname' => $fieldname,
                        'language' => $language,
                        'type' => $keyConfig->getType(),
                    ];

                    $encodedData = [];

                    if ($fd instanceof NormalizerInterface) {
                        $normalizedData = $fd->normalize($value, [
                            'object' => $object,
                            'fieldDefinition' => $fd,
                        ]);

                        /** @var MarshallerService $marshallerService */
                        $marshallerService = \Pimcore::getContainer()->get(MarshallerService::class);

                        if ($marshallerService->supportsFielddefinition('classificationstore', $fd->getFieldtype())) {
                            $marshaller = $marshallerService->buildFieldefinitionMarshaller('classificationstore', $fd->getFieldtype());
                            // TODO format only passed in for BC reasons (localizedfields). remove it as soon as marshal is gone
                            $encodedData = $marshaller->marshal($normalizedData, ['object' => $object, 'fieldDefinition' => $fd, 'format' => 'classificationstore']);
                        } else {
                            $encodedData['value'] = $normalizedData;
                        }
                    }

                    $data['value'] = $encodedData['value'] ?? null;
                    $data['value2'] = $encodedData['value2'] ?? null;

                    Helper::upsert($this->db, $dataTable, $data, $this->getPrimaryKey($dataTable));
                    $collectionToKeep[] = $collectionId;
                    $systemRowToDelete[] = $collectionId;
                }
            }
        }

        if (!$items && count($collectionMapping) > 0){
            foreach ($collectionMapping as $groupId => $collectionId) {
                if ($collectionId) {
                    $data = [
                        'id' => $objectId,
                        'collectionId' => $collectionId,
                        'groupId' => $groupId,
                        'keyId' => 0,
                        'fieldname' => $fieldname,
                        'language' => 'default',
                        'type' => '<system>' //on purpose to avoid conflict with real types, could be easily cleared
                    ];
                    Helper::upsert($this->db, $dataTable, $data, $this->getPrimaryKey($dataTable));
                    $groupsToKeep[] = $groupId;
                    $collectionToKeep[] = $collectionId;
                }
            }
        }

        // Delete the groups that are not found anymore
        $this->db->executeQuery(
            sprintf(
                'DELETE FROM %s WHERE %s = %s AND %s = %s AND %s NOT IN (?)',
                $groupsTable,
                $this->db->quoteIdentifier('id'),
                $objectId,
                $this->db->quoteIdentifier('fieldname'),
                $this->db->quote($fieldname),
                $this->db->quoteIdentifier('groupId'),
            ),
            [array_unique($groupsToKeep)],
            [ArrayParameterType::INTEGER]
        );

        // Delete the collections that are not found anymore or the system rows that are not needed anymore as the ones with real value and keyId is filled
        $this->db->executeQuery(
            sprintf(
                'DELETE FROM %s WHERE %s = %s AND %s = %s AND (%s NOT IN (?) OR (%s = %s AND %s IN (?)))',
                $dataTable,
                $this->db->quoteIdentifier('id'),
                $objectId,
                $this->db->quoteIdentifier('fieldname'),
                $this->db->quote($fieldname),
                $this->db->quoteIdentifier('collectionId'),
                $this->db->quoteIdentifier('type'),
                $this->db->quote('<system>'),
                $this->db->quoteIdentifier('groupId'),
            ),
            [array_unique($collectionToKeep), $systemRowToDelete],
            [ArrayParameterType::INTEGER, ArrayParameterType::INTEGER]
        );

    }

    public function delete(): void
    {
        $object = $this->model->getObject();
        $objectId = $object->getId();
        $dataTable = $this->getDataTableName();
        $groupsTable = $this->getGroupsTableName();

        // remove relations
        $this->db->delete($dataTable, ['id' => $objectId]);
        $this->db->delete($groupsTable, ['id' => $objectId]);
    }

    /**
     * @throws \Exception
     */
    public function load(): void
    {
        $classificationStore = $this->model;
        $object = $this->model->getObject();
        $dataTableName = $this->getDataTableName();
        $objectId = $object->getId();
        $fieldname = $this->model->getFieldname();
        $groupsTableName = $this->getGroupsTableName();

        $query = 'SELECT * FROM ' . $groupsTableName . ' WHERE id = ' . $this->db->quote($objectId) . ' AND fieldname = ' . $this->db->quote($fieldname);

        $data = $this->db->fetchAllAssociative($query);
        $list = [];

        foreach ($data as $item) {
            $list[$item['groupId']] = true;
        }

        $query = 'SELECT * FROM ' . $dataTableName . ' WHERE id = ' . $this->db->quote($objectId) . ' AND fieldname = ' . $this->db->quote($fieldname);

        $data = $this->db->fetchAllAssociative($query);

        $groupCollectionMapping = [];

        foreach ($data as $item) {
            if (!isset($list[$item['groupId']])) {
                continue;
            }

            $groupId = $item['groupId'];
            $keyId = $item['keyId'];
            $collectionId = $item['collectionId'];
            $groupCollectionMapping[$groupId] = $collectionId;

            $value = [
                'value' => $item['value'],
                'value2' => $item['value2'],
            ];

            $keyConfig = DefinitionCache::get($keyId);
            if (!$keyConfig) {
                Logger::error('Could not resolve key with ID: ' . $keyId);

                continue;
            }

            $fd = Service::getFieldDefinitionFromKeyConfig($keyConfig);

            if ($fd instanceof NormalizerInterface) {
                /** @var MarshallerService $marshallerService */
                $marshallerService = \Pimcore::getContainer()->get(MarshallerService::class);

                if ($marshallerService->supportsFielddefinition('classificationstore', $fd->getFieldtype())) {
                    $unmarshaller = $marshallerService->buildFieldefinitionMarshaller('classificationstore', $fd->getFieldtype());
                    // TODO format only passed in for BC reasons (localizedfields). remove it as soon as marshal is gone
                    $value = $unmarshaller->unmarshal($value, ['object' => $object, 'fieldDefinition' => $fd, 'format' => 'classificationstore']);
                } else {
                    $value = $value['value'];
                }

                $value = $fd->denormalize($value, [
                    'object' => $object,
                    'fieldDefinition' => $fd,
                ]);
            }

            $language = $item['language'];
            $classificationStore->setLocalizedKeyValue($groupId, $keyId, $value, $language);
        }

        $classificationStore->setActiveGroups($list);
        $classificationStore->setGroupCollectionMappings($groupCollectionMapping);
        $classificationStore->resetDirtyMap();
    }

    public function createUpdateTable(): void
    {
        $groupsTable = $this->getGroupsTableName();
        $dataTable = $this->getDataTableName();

        $this->db->executeQuery('CREATE TABLE IF NOT EXISTS `' . $groupsTable . '` (
            `id` INT(11) UNSIGNED NOT NULL,
            `groupId` INT(11) UNSIGNED NOT NULL,
            `fieldname` VARCHAR(70) NOT NULL,
            PRIMARY KEY (`id`, `fieldname`, `groupId`),
            CONSTRAINT `'.self::getForeignKeyName($groupsTable, 'id').'` FOREIGN KEY (`id`) REFERENCES `objects` (`id`) ON DELETE CASCADE,
            CONSTRAINT `'.self::getForeignKeyName($groupsTable, 'groupId').'` FOREIGN KEY (`groupId`) REFERENCES `classificationstore_groups` (`id`) ON DELETE CASCADE
        ) DEFAULT CHARSET=utf8mb4;');

        $this->db->executeQuery('CREATE TABLE IF NOT EXISTS `' . $dataTable . '` (
            `id` INT(11) UNSIGNED NOT NULL,
            `collectionId` BIGINT(20) NULL,
            `groupId` INT(11) UNSIGNED NOT NULL,
            `keyId` BIGINT(20) NOT NULL,
            `value` LONGTEXT NULL,
	        `value2` LONGTEXT NULL,
            `fieldname` VARCHAR(70) NOT NULL,
            `language` VARCHAR(10) NOT NULL,
            `type` VARCHAR(50) NULL,
            PRIMARY KEY (`id`, `fieldname`, `groupId`, `keyId`, `language`),
            INDEX `keyId` (`keyId`),
            INDEX `language` (`language`),
            INDEX `groupKeys` (`id`, `fieldname`, `groupId`),
            CONSTRAINT `'.self::getForeignKeyName($dataTable, 'id').'` FOREIGN KEY (`id`) REFERENCES `objects` (`id`) ON DELETE CASCADE,
            CONSTRAINT `'.self::getForeignKeyName($dataTable, 'id__fieldname__groupId').'` FOREIGN KEY (`id`, `fieldname`, `groupId`) REFERENCES `' . $groupsTable . '` (`id`, `fieldname`, `groupId`) ON DELETE CASCADE
        ) DEFAULT CHARSET=utf8mb4;');

        $this->tableDefinitions = [];

        $this->handleEncryption($this->model->getClass(), [$groupsTable, $dataTable]);
    }
}
