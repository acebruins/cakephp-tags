<?php
/**
 * Copyright 2009-2014, Cake Development Corporation (http://cakedc.com)
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright 2009-2014, Cake Development Corporation (http://cakedc.com)
 * @license MIT License (http://www.opensource.org/licenses/mit-license.php)
 */

namespace Tags\Model\Behavior;

use Cake\Core\Configure;
use Cake\ORM\Behavior;
use Cake\ORM\Table;
use Cake\Utility\Hash;
use Cake\Utility\Inflector;

/**
 * Taggable Behavior
 *
 * @package tags
 * @subpackage tags.models.behaviors
 */
class TaggableBehavior extends Behavior
{

/**
 * Default config
 *
 * separator                - separator used to enter a lot of tags, comma by default
 * tagAlias                 - model alias for Tag model
 * tagClass                 - class name of the table storing the tags
 * taggedClass              - class name of the HABTM association table between tags and models
 * field                    - the fieldname that contains the raw tags as string
 * foreignKey               - foreignKey used in the HABTM association
 * associationForeignKey    - associationForeignKey used in the HABTM association
 * automaticTagging         - if set to true you don't need to use saveTags() manually
 * language                 - only tags in a certain language, string or array
 * taggedCounter            - true to update the number of times a particular tag was used for a specific record
 * unsetInAfterFind         - unset 'Tag' results in afterFind
 * deleteTagsOnEmptyField   - delete associated Tags if field is empty.
 * resetBinding             - reset the bindModel() calls, default is false.
 *
 * @var array
 */
    protected $_defaultConfig = [
        'separator' => ',',
        'field' => 'tag',
        'tagAlias' => 'Tags',
        'tagClass' => 'Tags.Tags',
        'taggedAlias' => 'Tagged',
        'taggedClass' => 'Tags.Tagged',
        'foreignKey' => 'foreign_key',
        'targetForeignKey' => 'tag_id',
        'cacheOccurrence' => true,
        'automaticTagging' => true,
        'unsetInAfterFind' => false,
        'resetBinding' => false,
        'taggedCounter' => false,
        'deleteTagsOnEmptyField' => false
    ];

/**
 * Constructor
 *
 * @param \Cake\ORM\Table $table The table this behavior is attached to.
 * @param array $config The settings for this behavior.
 */
    public function __construct(Table $table, array $config = [])
    {
        parent::__construct($table, $config);
        $this->_table = $table;
        $this->_config['withModel'] = $this->_config['taggedClass'];
        $this->bindTagAssociations();
    }

/**
 * bindTagAssociations
 *
 * @return void
 */
    public function bindTagAssociations()
    {
        extract($this->_config);

        $this->_table->hasMany($taggedAlias, [
            'propertyName' => 'tagged',
            'className' => $taggedClass
        ]);

        $this->_table->belongsToMany($tagAlias, [
            'propertyName' => 'tag',
            'className' => $tagClass,
            'foreignKey' => $foreignKey,
            'targetForeignKey' => $targetForeignKey,
            'joinTable' => 'tagged',
            'unique' => true,
            'conditions' => array(
                $taggedAlias . '.model' => $this->name()
            ),
            'fields' => '',
            'dependent' => true,
            'with' => $withModel
        ]);
    }

/**
 * Disassembles the incoming tag string by its separator and identifiers and trims the tags.
 *
 * @param string $string Incoming tag string.
 * @param string $separator Separator character.
 * @return array Array of 'tags' and 'identifiers', use extract to get both vars out of the array if needed.
 */
    public function disassembleTags($string = '', $separator = ',')
    {
        $array = explode($separator, $string);

        $tags = $identifiers = array();
        foreach ($array as $tag) {
            $identifier = null;
            if (strpos($tag, ':') !== false) {
                $t = explode(':', $tag);
                $identifier = trim($t[0]);
                $tag = $t[1];
            }
            $tag = trim($tag);
            if (!empty($tag)) {
                $key = $this->multibyteKey($tag);
                if (empty($tags[$key]) && (empty($identifiers[$key]) || !in_array($identifier, $identifiers[$key]))) {
                    $tags[] = array('name' => $tag, 'identifier' => $identifier, 'keyname' => $key);
                    $identifiers[$key][] = $identifier;
                }
            }
        }

        return compact('tags', 'identifiers');
    }

/**
 * Saves a string of tags.
 *
 * @param string $string Comma separeted list of tags to be saved. Tags can contain special tokens called `identifiers´
 *     to namespace tags or classify them into catageories. A valid string is "foo, bar, cakephp:special". The token
 *     `cakephp´ will end up as the identifier or category for the tag `special´.
 * @param mixed $foreignKey The identifier for the record to associate the tags with.
 * @param bool $update True will remove tags that are not in the $string, false won't do this and just add new tags
 *      without removing existing tags associated to the current set foreign key.
 * @return bool
 */
    public function saveTags($string = null, $foreignKey = null, $update = true)
    {
        if (is_string($string) && !empty($string) && (!empty($foreignKey) || $foreignKey === false)) {
            $tagAlias = $this->_config['tagAlias'];
            $taggedAlias = $this->_config['taggedAlias'];
            $tagModel = $this->_table->{$tagAlias};

            extract($this->disassembleTags($string, $this->_config['separator']));

            if (!empty($tags)) {
                $conditions = array();
                foreach ($tags as $tag) {
                    $conditions['OR'][] = array(
                        $tagModel->alias() . '.identifier' => $tag['identifier'],
                        $tagModel->alias() . '.keyname' => $tag['keyname'],
                    );
                }
                $existingTags = $tagModel->find('all', array(
                    'contain' => array(),
                    'conditions' => $conditions,
                    'fields' => array(
                        $tagModel->alias() . '.identifier',
                        $tagModel->alias() . '.keyname',
                        $tagModel->alias() . '.name',
                        $tagModel->alias() . '.id'
                    )
                ));

                if (!empty($existingTags)) {
                    foreach ($existingTags as $existing) {
                        $existingTagKeyNames[] = $existing[$tagAlias]['keyname'];
                        $existingTagIds[] = $existing[$tagAlias]['id'];
                        $existingTagIdentifiers[$existing[$tagAlias]['keyname']][] = $existing[$tagAlias]['identifier'];
                    }
                    $newTags = array();
                    foreach ($tags as $possibleNewTag) {
                        $key = $possibleNewTag['keyname'];
                        if (!in_array($key, $existingTagKeyNames)) {
                            array_push($newTags, $possibleNewTag);
                        } elseif (!empty($identifiers[$key])) {
                            $newIdentifiers = array_diff($identifiers[$key], $existingTagIdentifiers[$key]);
                            foreach ($newIdentifiers as $identifier) {
                                array_push($newTags, array_merge($possibleNewTag, compact('identifier')));
                            }
                            unset($identifiers[$key]);
                        }
                    }
                } else {
                    $existingTagIds = $alreadyTagged = array();
                    $newTags = $tags;
                }
                foreach ($newTags as $key => $newTag) {
                    $entity = $tagModel->newEntity($newTag);
                    $tagModel->save($entity);
                    $newTagIds[] = $entity->id;
                }

                if ($foreignKey !== false) {
                    if (!empty($newTagIds)) {
                        $existingTagIds = array_merge($existingTagIds, $newTagIds);
                    }
                    $tagged = $tagModel->{$taggedAlias}->find('all', array(
                        'contain' => array(
                            'Tagged'
                        ),
                        'conditions' => array(
                            $taggedAlias . '.model' => $this->name(),
                            $taggedAlias . '.foreign_key' => $foreignKey,
                            $taggedAlias . '.language' => Configure::read('Config.language'),
                            $taggedAlias . '.tag_id' => $existingTagIds),
                        'fields' => 'Tagged.tag_id'
                    ));

                    $deleteAll = array(
                        $taggedAlias . '.foreign_key' => $foreignKey,
                        $taggedAlias . '.model' => $this->name());

                    if (!empty($tagged)) {
                        $alreadyTagged = Hash::extract($tagged, "{n}.{$taggedAlias}.tag_id");
                        $existingTagIds = array_diff($existingTagIds, $alreadyTagged);
                        $deleteAll['NOT'] = array($taggedAlias . '.tag_id' => $alreadyTagged);
                    }

                    $oldTagIds = array();

                    if ($update == true) {
                        $oldTagIds = $tagModel->{$taggedAlias}->find('all', array(
                            'contain' => array(
                                'Tagged'
                            ),
                            'conditions' => array(
                                $taggedAlias . '.model' => $this->name(),
                                $taggedAlias . '.foreign_key' => $foreignKey,
                                $taggedAlias . '.language' => Configure::read('Config.language')),
                            'fields' => 'Tagged.tag_id'
                        ))->hydrate(false)->toArray();

                        $oldTagIds = Hash::extract($oldTagIds, '{n}.Tagged.tag_id');
                        $tagModel->{$taggedAlias}->deleteAll($deleteAll, false);
                    } elseif ($this->_config['taggedCounter'] && !empty($alreadyTagged)) {
                        $tagModel->{$taggedAlias}->updateAll(
                            array('times_tagged' => 'times_tagged + 1'),
                            array('Tagged.tag_id' => $alreadyTagged)
                        );
                    }

                    foreach ($existingTagIds as $tagId) {
                        $data[$taggedAlias]['tag_id'] = $tagId;
                        $data[$taggedAlias]['model'] = $this->name();
                        $data[$taggedAlias]['foreign_key'] = $foreignKey;
                        $data[$taggedAlias]['language'] = Configure::read('Config.language');
						$entity = $tagModel->{$taggedAlias}->newEntity($data);
                        $tagModel->{$taggedAlias}->save($entity);
                    }

                    //To update occurrence
                    if ($this->_config['cacheOccurrence']) {
                        $newTagIds = $tagModel->{$taggedAlias}->find('all', array(
                            'contain' => array(
                                'Tagged'
                            ),
                            'conditions' => array(
                                $taggedAlias . '.model' => $this->name(),
                                $taggedAlias . '.foreign_key' => $foreignKey,
                                $taggedAlias . '.language' => Configure::read('Config.language')),
                            'fields' => 'Tagged.tag_id'
                        ))->hydrate(false)->toArray();

                        if (!empty($newTagIds)) {
                            $newTagIds = Hash::extract($newTagIds, '{n}.Tagged.tag_id');
                        }

                        $this->cacheOccurrence(array_merge($oldTagIds, $newTagIds));
                    }
                }
            }
            return true;
        }
        return false;
    }

/**
 * Cache the weight or occurence of a tag in the tags table
 *
 * @param int|string|array $tagIds List of tag UUIDs.
 * @return void
 */
    public function cacheOccurrence($tagIds)
    {
        if (!is_array($tagIds)) {
            $tagIds = array($tagIds);
        }

        foreach ($tagIds as $tagId) {
            $fieldName = Inflector::underscore($this->name()) . '_occurrence';
            $tagModel = $this->_table->{$this->_config['tagAlias']};
            $taggedModel = $tagModel->{$this->_config['taggedAlias']};
            $primaryKey = $tagModel->primaryKey();
            $primaryKey = array_shift($primaryKey);
            $data = array($primaryKey => $tagId);

            if ($tagModel->hasField($fieldName)) {
                $data[$fieldName] = $taggedModel->find('count', array(
                    'conditions' => array(
                        'Tagged.tag_id' => $tagId,
                        'Tagged.model' => $this->name()
                    )
                ));
            }

            $data['occurrence'] = $taggedModel->find('count', array(
                'conditions' => array(
                    'Tagged.tag_id' => $tagId
                )
            ));

            $tagModel->save($tagModel->newEntity($data), array(
                'validate' => false,
                'callbacks' => false));
        }
    }

/**
 * Creates a multibyte safe unique key.
 *
 * @param string $string Tag name string.
 * @return string Multibyte safe key string.
 */
    public function multibyteKey($string = null)
    {
        $str = mb_strtolower($string);
        $str = preg_replace('/\xE3\x80\x80/', ' ', $str);
        $str = str_replace(array('_', '-'), '', $str);
        $str = preg_replace('#[:\#\*"()~$^{}`@+=;,<>!&%\.\]\/\'\\\\|\[]#', "\x20", $str);
        $str = str_replace('?', '', $str);
        $str = trim($str);
        $str = preg_replace('#\x20+#', '', $str);
        return $str;
    }

/**
 * Generates comma-delimited string of tag names from tag array(), needed for
 * initialization of data for text input
 *
 * Example usage (only 'Tag.name' field is needed inside of method):
 * <code>
 * $this->Blog->hasAndBelongsToMany['Tag']['fields'] = array('name', 'keyname');
 * $blog = $this->Blog->read(null, 123);
 * $blog['Blog']['tags'] = $this->Blog->Tags->tagArrayToString($blog['Tag']);
 * </code>
 *
 * @param array $data Tag data array to convert to string.
 * @return string
 */
    public function tagArrayToString($data = null)
    {
        if ($data) {
            $tags = array();
            foreach ($data as $tag) {
                if (!empty($tag['identifier'])) {
                    $tags[] = $tag['identifier'] . ':' . $tag['name'];
                } else {
                    $tags[] = $tag['name'];
                }
            }
            return join($this->_config['separator'] . ' ', $tags);
        }
        return '';
    }

/**
 * afterSave callback.
 *
 * @param array $created True if new record, false otherwise.
 * @param array $options Options array.
 * @return void
 */
    public function afterSave($created, $options = array())
    {
        if (!isset($this->_table->data[$this->_config['field']])) {
            return;
        }
        $field = $this->_table->data[$this->_config['field']];
        $hasTags = !empty($field);
        if ($this->_config['automaticTagging'] === true && $hasTags) {
            $this->saveTags($field, $this->_table->id);
        } elseif (!$hasTags && $this->_config['deleteTagsOnEmptyField']) {
            $this->deleteTagged();
        }
    }

/**
 * Delete associated Tags if record has no tags and deleteTagsOnEmptyField is true.
 *
 * @param mixed $id Foreign key of the model, string for UUID or integer.
 * @return void
 */
    public function deleteTagged($id = null)
    {
        extract($this->_config);
        $tagModel = $this->_table->{$tagAlias};
        if (is_null($id)) {
            $id = $this->_table->id;
        }
        $tagModel->{$taggedAlias}->deleteAll(
            array(
                $taggedAlias . '.model' => $this->name(),
                $taggedAlias . '.foreign_key' => $id,
            )
        );
    }

/**
 * afterFind Callback
 *
 * @param array $results Find results.
 * @param bool $primary True if primary model, false if associated.
 * @return array
 */
    public function afterFind($results, $primary = false)
    {
        extract($this->_config);

        list($plugin, $class) = pluginSplit($tagClass);
        if ($this->name() === $class) {
            return $results;
        }

        foreach ($results as $key => $row) {
            $row[$field] = '';
            if (isset($row[$tagAlias]) && !empty($row[$tagAlias])) {
                $row[$field] = $this->tagArrayToString($model, $row[$tagAlias]);
                if ($unsetInAfterFind == true) {
                    unset($row[$tagAlias]);
                }
            }
            $results[$key] = $row;
        }
        return $results;
    }

/**
 * Get name of table.
 *
 * @return string Name of table.
 */
    public function name()
    {
        return get_class($this->_table);
    }
}
