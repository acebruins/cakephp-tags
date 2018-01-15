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

namespace Tags\View\Helper;

use Cake\Utility\Hash;
use Cake\View\Helper;

/**
 * Tag cloud helper
 *
 * @property \Cake\View\Helper\HtmlHelper $Html
 */
class TagCloudHelper extends Helper {

	/**
	 * @var array
	 */
	public $helpers = [
		'Html'
	];

	/**
	 * Method to output a tag-cloud formatted based on the weight of the tags
	 *
	 * @param array $tags Tag array to display.
	 * @param array $options Display options. Valid keys are:
	 *  - shuffle: true to shuffle the tag list, false to display them in the same order than passed [default: true]
	 *  - extract: Set::extract() compatible format string. Path to extract weight values from the $tags array
	 *      [default: {n}.Tag.weight]
	 *  - before: string to be displayed before each generated link. "%size%" will be replaced with tag size calculated
	 *      from the weight [default: empty]
	 *  - after: string to be displayed after each generated link. "%size%" will be replaced with tag size calculated from
	 *      the weight [default: empty]
	 *  - maxSize: size of the heaviest tag [default: 160]
	 *  - minSize: size of the lightest tag [default: 80]
	 *  - url: an array containing the default url
	 *  - named: the named parameter used to send the tag [default: by].
	 * @return string
	 */
	public function display(array $tags, $options = []) {
		if (empty($tags)) {
			return '';
		}
		$defaults = [
			'tagModel' => 'tags',
			'shuffle' => true,
			'extract' => '{n}.weight',
			'before' => '',
			'after' => '',
			'maxSize' => 160,
			'minSize' => 80,
			'url' => [
				'controller' => 'search'
			],
			'named' => 'by'
		];
		$options = array_merge($defaults, $options);

		$tags = $this->calculateWeights($tags);

		$weights = Hash::extract($tags, $options['extract']);
		$maxWeight = max($weights);
		$minWeight = min($weights);

		// find the range of values
		$spread = $maxWeight - $minWeight;
		if ($spread == 0) {
			$spread = 1;
		}

		if ($options['shuffle'] == true) {
			shuffle($tags);
		}

		$cloud = null;
		foreach ($tags as $tag) {
			$tagWeight = $tag['weight'];

			$size = $options['minSize'] + (
				($tagWeight - $minWeight) * (
					($options['maxSize'] - $options['minSize']) / $spread
				)
			);
			$size = $tag['size'] = ceil($size);

			$cloud .= $this->_replace($options['before'], $size);
			$cloud .= $this->Html->link(
				$tag[$options['tagModel']]['name'],
				$this->_tagUrl($tag, $options),
				['id' => 'tag-' . $tag[$options['tagModel']]['id']]
			) . ' ';
			$cloud .= $this->_replace($options['after'], $size);
		}

		return $cloud;
	}

	/**
	 * @param array $entities
	 * @param array $config
	 *
	 * @return array
	 */
	public function calculateWeights(array $entities, array $config = []) {
		$config += [
			'minSize' => 10,
			'maxSize' => 20,
		];

		$weights = Hash::extract($entities, '{n}.occurrence');
		$maxWeight = max($weights);
		$minWeight = min($weights);

		$spread = $maxWeight - $minWeight;
		if ($spread == 0) {
			$spread = 1;
		}

		foreach ($entities as $key => $result) {
			$size = $config['minSize'] + (
					($result['occurrence'] - $minWeight) * (
						($config['maxSize'] - $config['minSize']) / ($spread)
					)
				);
			$entities[$key]['weight'] = ceil($size);
		}

		return $entities;
	}

	/**
	 * Generates the URL for a tag
	 *
	 * @param array $tag Tag to generate URL for.
	 * @param array $options Tag options.
	 * @return array|string Tag URL.
	 */
	protected function _tagUrl($tag, $options) {
		$options['url'][$options['named']] = $tag[$options['tagModel']]['keyname'];
		return $options['url'];
	}

	/**
	 * Replaces %size% in strings with the calculated "size" of the tag
	 *
	 * @param string $string Template string.
	 * @param float $size Replacement size.
	 * @return string Final string.
	 */
	protected function _replace($string, $size) {
		return str_replace('%size%', $size, $string);
	}

}