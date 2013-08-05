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

        /**
         * Generate a hierarchical "tree view" from a array of MODX resources
         */
		class MyTreeView {

			/** @var modX  */
			public $modx;

			/** @var array */
			protected $currentSelection = array();

			/** @var modResource[] Array of MODX resources for the tree */
			private $resources = array();

            /** @var string $indentor The string repeated before a child resource to indicate indentation/level relationship */
            private $indentor = '- ';

            /** @var bool Whether or not to log timings during the tree build - for development only */
            private $debug_timings = FALSE;

			private $tstart = 0;

			private $timings = array();

            /**
             * @param modX $modx Local reference to MODX instance
             */
			public function __construct(&$modx) {
				$this->modx = $modx;
			}

			/**
			 * Recursively sort the array of resources into a nested array based on each elements parent.
			 *
			 * @param modResource[] $elements An array of MODX resources
			 * @param int $parentId
			 * @return array
			 */
			private function build_tree(array $elements, $parentId = 0) {
				$branch = array();

				foreach ($elements as $element) {
					if(!is_array($element)) {
						$elementArr = array('id'=>$element->get('id'),'pagetitle'=>$element->get('pagetitle'),'parent'=>$element->get('parent'));
					}
					else {
						$elementArr = $element;
					}

					if ($elementArr['parent'] == $parentId) {
						$children = $this->build_tree($elements, $elementArr['id']);
						if ($children) {
							$elementArr['children'] = $children;
						}
						$branch[] = $elementArr;
					}
				}

				return $branch;
			}


            /**
             * Flatten the nested array using text to indicate indentation/levels
             *
             * @param array $arr The source nested array of resources
             * @param array $output The flattened array we build as we process the nested array
             * @param int $index
             * @return array
             */
			private function generate_array(array $arr, &$output = array(), $index = 0) {
				foreach($arr as $item)
				{
					$selected = in_array($item['id'],$this->currentSelection);

					$output[$item['id']] = array(
						'value' => $item['id'],
						'text' => str_repeat($this->indentor, $index) . $item['pagetitle'],
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

            /**
             * @param string Which point we are recording
             */
            private function debugTimings($msg) {
                if($this->debug_timings) {
                    $this->timings[$msg] = microtime(TRUE) - $this->tstart;
                }
            }

            /**
             * Start the debug if necessary
             */
            private function startDebug() {
              if($this->debug_timings) {
                   $this->tstart = microtime(TRUE);
              }
            }

			/**
			 * Generate the array of MODX resources
			 *
			 * @todo Allow searching by providing a query in $params['where'] (in json)
			 * @param array $parents
			 * @param $params
			 */
			private function load_resources(array $parents, $params) {
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

				$this->debugTimings('Got children of initial parents');

				// Build the query to load the actual resource list, giving an array of parents to start from

				/** @var xPDOQuery $c */
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

				$this->resources = $this->modx->getCollection('modResource',$c);
			}

            /**
             * Get a list of resources and build a hierarchical tree list of them
             *
             * @param array $params Options for the list, including any currently selected value
             * @return array
             */
			public function Process(array $params = array()) {

				$this->startDebug();

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

				$this->load_resources($parents,$params);


                $this->debugTimings('Got matching collection');

				//Now build a tree hierarchy from the resources
				$resources = $this->build_tree($this->resources);

                $this->debugTimings('Built tree');

				/*
				  See if we have a selected value
				 */
				if(isset($params['selected']) && !empty($params['selected'])) {
					$this->setCurrentSelection(array($params['selected']));
				}

				/* iterate */
				$opts = array();
				$opts[] = array('value' => '','text' => '-','selected' => count($this->currentSelection)>0);

				$opts = $this->generate_array($resources);

                $this->debugTimings('Generated array');

                return $opts;

			}

			/**
			 * Output the array of pages as a JS array to use in the ExtJS drop down values
			 *
			 * @param array $Pages
			 * @return string
			 */
			public function FormatArray(array $Pages) {
				$selectPairs = array();

				foreach($Pages as $pageid => $data) {
					$selectPairs[] = '["'.$data['text'].'",'.(int)$data['value'].']'."\r\n";
				}

				return implode(",\r\n",$selectPairs);
			}
		}

		$Viewer = new MyTreeView($modx);

		$params = array(
			'showNone'=>1,
			'selected' => isset($_GET['current']) && !empty($_GET['current']) ? (int)$_GET['current'] : '',

		);

		$pagesList = $Viewer->Process($params);

		$selectItems = $this->FormatArray($pagesList);

	}
}

/**
 * Now output the JS for the dialog, embedding the page list
 */
ob_start();
echo "var Global_selectItems = [".$selectItems."];";
include("modxlink.js");
ob_end_flush();