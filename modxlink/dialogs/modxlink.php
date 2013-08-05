<?php
/**
 * Boulder Design Ltd
 * User: Ross
 * Created: 03/07/13
 */
$core_path = htmlentities(strip_tags($_GET['p']));
if(file_exists($core_path.'config.core.php')) {
	require_once($core_path.'config.core.php');
	define(MODX_API_MODE,TRUE);
	require_once($core_path.'index.php');
	/** @var modX $modx */
	if($modx instanceof modX) {


		class MyTreeView {

			/** @var modX  */
			public $modx;

			/** @var array */
			protected $currentSelection = array();

			private $tstart = 0;

			private $timings = array();

			public function __construct(&$modx) {
				$this->modx = $modx;
			}

			function buildTree(array $elements, $parentId = 0) {
				$branch = array();

				foreach ($elements as $element) {
					if(!is_array($element)) {
						$elementArr = array('id'=>$element->get('id'),'pagetitle'=>$element->get('pagetitle'),'parent'=>$element->get('parent'));
					}
					else {
						$elementArr = $element;
					}

					if ($elementArr['parent'] == $parentId) {
						$children = $this->buildTree($elements, $elementArr['id']);
						if ($children) {
							$elementArr['children'] = $children;
						}
						$branch[] = $elementArr;
					}
				}

				return $branch;
			}



			function generate_array(array $arr, &$output = array(), $index = 0)
			{
				foreach($arr as $item)
				{
					$selected = in_array($item['id'],$this->currentSelection);

					$output[$item['id']] = array(
						'value' => $item['id'],
						'text' => str_repeat('- ', $index) . $item['pagetitle'],
						'selected' => $selected
					);
					if(isset($item['children']))
					{
						$this->generate_array($item['children'], $output, $index + 1);
					}
				}
				return $output;
			}

			/**
			 * @param array $selection
			 */
			public function setCurrentSelection(array $selection) {
				$this->currentSelection = $selection;
			}


			public function process(array $params = array()) {

				$this->tstart = microtime(TRUE);

				//$parents = $this->getInputOptions();
				$parents = array(0);
				if(isset($params['parents'])) {
					if(is_array($params['parents'])) {
						$parents = $params['parents'];
					}
					else {
						$parents = array((int)$params['parents']);
					}
				}
				else {
					$parents = array(0);
				}

				$params['depth'] = !empty($params['depth']) ? $params['depth'] : 10;

				/* get all children */
				$ids = array();

					foreach ($parents as $parent) {

						$ids[] = $parent;
						$children = $this->modx->getChildIds($parent,$params['depth'],array(
						                                                                              'context' => 'web',
						                                                                         ));
						$ids = array_merge($ids,$children);
					}
					$ids = array_unique($ids);


				$this->timings['Got children of initial parents'] = microtime(TRUE) - $this->tstart;

				$c = $this->modx->newQuery('modResource');
				$c->leftJoin('modResource','Parent');
				if (!empty($ids)) {
					$c->where(array('modResource.id:IN' => $ids));
				} else if (!empty($parents) && $parents[0] == 0) {
					$c->where(array('modResource.parent' => 0));
				}

				// No point listing deleted resources
				$c->where(array('deleted'=>0));

				if (!empty($params['where'])) {
					$params['where'] = $this->modx->fromJSON($params['where']);
					$c->where($params['where']);
				}
				if (!empty($params['limitRelatedContext']) && ($params['limitRelatedContext'] == 1 || $params['limitRelatedContext'] == 'true')) {
					$context_key = $this->modx->resource->get('context_key');
					$c->where(array('modResource.context_key' => $context_key));
				}
				$c->sortby('Parent.menuindex,modResource.menuindex','ASC');
				if (!empty($params['limit'])) {
					$c->limit($params['limit']);
				}

				$c->orCondition(array('id'=>1));

				$resources = $this->modx->getCollection('modResource',$c);


				$this->timings['Got matching collection'] = microtime(TRUE) - $this->tstart;

				//Now build a tree hierarchy from the resources
				$resources = $this->buildTree($resources);

				$this->timings['Built tree'] = microtime(TRUE) - $this->tstart;

				/*
				  Get any existing TV value (which may be a delimited string, with ||)
				 */
				if(isset($params['selected']) && !empty($params['selected'])) {
					$this->setCurrentSelection(array($params['selected']));
				}

				/* iterate */
				$opts = array();
				//if (!empty($params['showNone'])) {
					$opts[] = array('value' => '','text' => '-','selected' => count($this->currentSelection)>0);
				//}

				$opts = $this->generate_array($resources);

				$this->timings['Generated array'] = microtime(TRUE) - $this->tstart;

				//print_r($this->timings);

				/** @var modResource $resource */
				/*foreach ($resources as $resource) {

					$selected = in_array($resource['id'],$currentSelection);
					//== $this->tv->get('value');
					$prefix = $resource->get['parent'] > 0 ? $indent : '';

					$opts[] = array(
						'value' => $resource['id'],
						'text' => $prefix.$resource['pagetitle'].' ('.$resource['id'].')',
						'selected' => $selected,
					);
				}*/
				//$this->setPlaceholder('opts',$opts);
				//if($params['return']) {
					return $opts;
				//}
			}
		}

		$Viewer = new MyTreeView($modx);

		$params = array(
			'showNone'=>1,
			'selected' => isset($_GET['current']) && !empty($_GET['current']) ? (int)$_GET['current'] : '',

		);

		$pagesList = $Viewer->process($params);
		$selectPairs = array();
		$selectItems = '';

		foreach($pagesList as $pageid => $data) {
			$selectPairs[] = '["'.$data['text'].'",'.(int)$data['value'].']'."\r\n";
		}

		$selectItems = implode(",\r\n",$selectPairs);


	}
}

ob_start();

echo "var Global_selectItems = [".$selectItems."];";

include("modxlink.js");
ob_end_flush();