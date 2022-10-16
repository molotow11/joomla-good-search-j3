<?php
/**
 * @package     Articles Good Search
 *
 * @copyright   Copyright (C) 2017 Joomcar extensions. All rights reserved.
 * @license     GNU General Public License version 2 or later.
 */
 
// no direct access
defined('_JEXEC') or die('Restricted access');

class ArticlesModelGoodSearch extends JModelList {
	var $input;
	var $db;
	var $module_id;
	var $module_helper;
	var $module_params;
	var $module_params_native;
	var $limit;
	var $limitstart;
	var $total_items;
	var $search_query;
	
	function __construct() {		
		$this->input = JFactory::getApplication()->input;
		$this->db = JFactory::getDBO();
		require_once(JPATH_SITE . "/modules/mod_articles_good_search/helper.php");
		$this->module_id = $this->input->get("moduleId", "", "int");
		$this->module_helper = new modArticlesGoodSearchHelper;
		$this->module_params = $this->module_helper->getModuleParams($this->module_id);
		$this->module_params_native = $this->module_helper->getModuleParams($this->module_id, true);
		
		if($this->module_params->savesearch && !JRequest::getVar("initial")) {
			$this->saveSearchSession();
		}
		
		if($this->module_params->savesearch && JFactory::getSession()->get("SaveSearchValues")
			&& $_GET['applySaved']
		) {
			$skip = array("option", "task", "view", "Itemid", "search_mode", "dynobox", "field_id", "field_type", "initial");
			foreach(JFactory::getSession()->get("SaveSearchValues") as $key=>$value) {
				if(in_array($key, $skip)) continue;
				JRequest::setVar($key, $value);
			}
		}
		
		if($this->module_params->search_sppagebuilder
			&& JFile::exists(JPATH_ADMINISTRATOR . '/components/com_sppagebuilder/sppagebuilder.php')
		) {
			$this->getItemsSP();
		}
		
		if($this->module_params->search_contacts_enabled) {
			$items_contact = $this->getItems(false, 'contact_details');
			foreach($items_contact as $k=>$item) {
				$items_contact[$k]->gsearch_item_type = 'contacts';
			} 
			$this->contact_items = $items_contact;
			$this->contact_items_count = $this->getItems(true, 'contact_details');
		}
		
		$this->search_query = $this->getSearchQuery();
		$this->total_items = $this->getItems(true);
	}
	
	function getItems($total = false, $context = 'content') {		
		$db = JFactory::getDBO();
		
		if($total) {
			$query = "SELECT COUNT(DISTINCT i.id)";
		}
		else {
			$featuredFirst = false;
			switch($this->module_params->include_featured) {
				case "First" :
					$featuredFirst = true;
				break;
				case "Only" : 
					$query .= " AND i.featured = 1";
				break;
				case "No" :
					$query .= " AND i.featured = 0";
				break;
			}
			
			$default_ordering = $featuredFirst ? 'featured' : $this->module_params->ordering_default;
			$orderby = JRequest::getVar("orderby", $default_ordering);
			$orderto = JRequest::getVar("orderto", $this->module_params->ordering_default_dir);
			
			$query = "SELECT i.*, GROUP_CONCAT(tm.tag_id) as tags, cat.title as category";

			//select field ordering value
			if($featuredFirst) {
				preg_match('/^field([0-9]+)$/', $this->module_params->ordering_default, $matches);
			}
			else {
				preg_match('/^field([0-9]+)$/', $orderby, $matches);
			}
			if(count($matches)) {
				$query .= ", fv2.value as {$matches[0]}";
			}
		}
		
		$query .= " FROM #__{$context} as i";
		$query .= " LEFT JOIN #__categories AS cat ON cat.id = i.catid";
		$query .= " LEFT JOIN #__menu AS menu ON menu.`link` = CONCAT('index.php?option=com_content&view=article&id=', i.id)";
		
		if(JRequest::getVar("keyword")
			&& $this->module_params->search_flexicontent_enabled
		) {
			$query .= " LEFT JOIN #__flexicontent_items_ext AS flexi ON flexi.item_id = i.id";
		}
		
		if(JRequest::getVar("keyword")) {
			//left join all fields values for keyword search
			//commented for prevent slow loading with big databases
			//$query .= " LEFT JOIN #__fields_values AS fv ON fv.item_id = i.id";
		}
		
		$query .= " LEFT JOIN #__contentitem_tag_map AS tm 
						ON tm.content_item_id = i.id";
			if($context == 'contact_details') {
				$query .= " AND type_alias = 'com_contact.contact'";
			}
			else {
				$query .= " AND type_alias = 'com_content.article'";
			}
			
		if(!$total) {
			//left join field ordering value
			if($featuredFirst) {
				preg_match('/^field([0-9]+)$/', $this->module_params->ordering_default, $matches);
			}
			else {
				preg_match('/^field([0-9]+)$/', $orderby, $matches);
			}
			if(count($matches)) {
				$query .= " LEFT JOIN #__fields_values AS fv2 ON fv2.item_id = i.id AND fv2.field_id = {$matches[1]}";
			}
		}
		
		if($context == 'contact_details') {
			$query .= " WHERE i.published = 1";
		}
		else {
			if($this->module_params->search_statuses) {
				$query .= " WHERE i.state IN ({$this->module_params->search_statuses})";
			}
			else {
				$query .= " WHERE i.state = 1";
			}
		}
		
		//publish up/down
		$jnow = JFactory::getDate();
		$now = $jnow->toSQL();
		$nullDate = $db->getNullDate();
		$query .= " AND (i.publish_up = ".$db->Quote($nullDate)." OR i.publish_up <= ".$db->Quote($now)." )";
		$query .= " AND (i.publish_down = ".$db->Quote($nullDate)." OR i.publish_down >= ".$db->Quote($now)." )";

		//category restriction
		if($this->module_params->restrict) {
			$module_params_native = $this->module_helper->getModuleParams($this->module_id, true);
			$category_restriction = $this->module_helper->getCategories(0, $module_params_native);
			if(count($category_restriction)) {
				$ids = Array();
				foreach($category_restriction as $c) {
					$ids[] = $c->id;
				}
				//added for compatibility with cv multicategories plugin
				if (JPluginHelper::isEnabled('system', 'cwmulticats')) {
					$query .= " AND (
									i.attribs REGEXP 'multicats\":\".*(".implode("|", $ids).")[^\]]\"'
									OR i.catid IN (".implode(",", $ids).")
								)";
				}
				else {
					$query .= " AND i.catid IN (".implode(",", $ids).")";
				}
			}			
		}
		
		//language filter
		$language = JFactory::getLanguage();
		$defaultLang = $language->getDefault();
		$currentLang = $language->getTag();
		$query .= " AND i.language IN ('*', '{$currentLang}')";
		
		//access filter
		$query .= " AND i.access IN(".implode(',', JFactory::getUser()->getAuthorisedViewLevels()).")";

		//general search query build
		if($context == 'content') {
			$query .= $this->search_query;
		}
		else {
			$query .= $this->getSearchQuery('contact_details'); // build it fresh for extra components (Contacts)
		}
	
		if(!$total) {
			$query .= " GROUP BY i.id";
			$query .= " ORDER BY ";
			switch($orderby) {
				case "title" :
					if(JRequest::getVar("orderto") == "") {
						$orderto = "ASC";
						JRequest::setVar("orderto", "asc");
					}
					if($context == "contact_details") {
						$query .= "i.name {$orderto}";
					}
					else {
						$query .= "i.title {$orderto}";
					}
				break;
				case "alias" :
					$query .= "i.alias {$orderto}";
				break;				
				case "created" :
					$query .= "i.created {$orderto}";
				break;				
				case "publish_up" :
					$query .= "i.publish_up {$orderto}";
				break;
				case "category" :
					$query .= "category {$orderto}";
				break;
				case "hits" :
					$query .= "i.hits {$orderto}";
				break;
				case "featured" :
					$query .= "i.featured {$orderto}";
					//order by field value
					preg_match('/^field([0-9]+)$/', $this->module_params->ordering_default, $matches);
					if(count($matches)) {
						$query .= ", {$this->module_params->ordering_default} {$orderto}";
					}
					else if($this->module_params->ordering_default == 'rand') {
						$currentSession = JFactory::getSession();    
						$sessionNum = substr(preg_replace('/[^0-9]/i','',$currentSession->getId()),2,3); 
						$query .= ", RAND({$sessionNum})";						
					}
					else {
						$query .= ", i.{$this->module_params->ordering_default} {$orderto}";
					}
				break;
				case "rand" :
					$currentSession = JFactory::getSession();    
					$sessionNum = substr(preg_replace('/[^0-9]/i','',$currentSession->getId()),2,3); 
					$query .= "RAND({$sessionNum})";
				break;
				case "ordering" :
					$query .= "i.ordering {$orderto}";
				break;
				case "id" :
				default :
					//order by field value
					preg_match('/^field([0-9]+)$/', $orderby, $matches);
					if(count($matches)) {
						$query .= "{$orderby} {$orderto}"; // natural sorting fix
					}
					else {
						$query .= "i.id {$orderto}";
					}
			}
		}

		if(isset($_GET['debug'])) {
			echo $query . "<hr />";
		}

		if($total) {
			$db->setQuery($query);
			$count = $db->loadResult();
			if($this->module_params->search_sppagebuilder
				&& JFile::exists(JPATH_ADMINISTRATOR . '/components/com_sppagebuilder/sppagebuilder.php')
			) {
				$count += $this->sp_items_count;				
			}
			if($this->module_params->search_contacts_enabled) {
				$count += $this->contact_items_count;
			}
			return $count;
		}
		else {
			$this->limitstart = $this->input->get("page-start", 0, "int");
			
			// added extra components search
			if($context == 'content' && 
				(($this->module_params->search_sppagebuilder 
				&& JFile::exists(JPATH_ADMINISTRATOR . '/components/com_sppagebuilder/sppagebuilder.php'))
				|| ($this->module_params->search_flexicontent_enabled 
				&& JFile::exists(JPATH_ADMINISTRATOR . '/components/com_flexicontent/flexicontent.php'))
				|| $this->module_params->search_contacts_enabled)
			) {
				$db->setQuery($query);
				$items = $db->loadObjectList();
				
				// Collect all items

				// Joomla contacts
				if($this->module_params->search_contacts_enabled) {
					$items = array_merge($this->contact_items, $items);
					$items = $this->sortMixedItems($items);
				}
				
				// SP Page builder
				if($this->module_params->search_sppagebuilder) {
					$items = array_merge($this->sp_items, $items); // add sp items		
					if(!$this->module_params->search_sppagebuilder_top) {
						$items = $this->sortMixedItems($items); // add default sorting
					}
				}
				
				if(count($items)) { // limit items
					$rows = Array();
					$total = $this->limit + $this->limitstart;
					for($i = $this->limitstart; $i < $total; $i++) {
						if($items[$i]) {
							$rows[] = $items[$i];
						}
					}
					$items = $rows;
					return $items;
				}
			}	
			
			// Standard output
			if($context == 'content') {
				$db->setQuery($query, $this->limitstart, $this->limit);
			}
			else {
				// additional components (Contacts)
				$db->setQuery($query);
			}
			try {
				$items = $db->loadObjectList();
			}
			catch(Exception $e) {
				//echo $e->getMessage();
				echo "Context: " . $context . "<br />";
				echo "BAD Query: " . $query;
			}

			return $items;
		}
	}
	
	function getSearchQuery($context = 'content') {
		$timezone = new DateTimeZone(JFactory::getConfig()->get('offset'));
		$query = "";

		//keyword
		if(JRequest::getVar("keyword")) {
			$keyword = strtoupper(JRequest::getVar("keyword"));
			$keyword = addslashes($keyword);
			$keyword = str_replace("/", "\\\\\\\/", $keyword);
			$keyword = str_replace("(", "\\\\(", $keyword);
			$keyword = str_replace(")", "\\\\)", $keyword);
			$keyword = str_replace("*", "\\\\*", $keyword);
			if($_GET['match']) {
				$query .= " AND (";
				$condition = $_GET['match'] == "all" ? " AND " : " OR ";
				foreach(explode(" ", $keyword) as $k=>$word) {
					$query .= $k > 0 ? $condition : "";
					if($context == 'content') {
						$query .= "(UPPER(i.title) REGEXP '[[:<:]]{$word}'";
						$query .= " OR UPPER(i.introtext) REGEXP '[[:<:]]{$word}'";
						$query .= " OR UPPER(i.fulltext) REGEXP '[[:<:]]{$word}'";
						$query .= " OR UPPER(i.metakey) LIKE '%{$word}%'";
						$query .= " OR UPPER(i.alias) LIKE '%{$word}%'";
						$query .= " OR UPPER(menu.`params`) LIKE '%{$word}%')";
					}
					else if($context == 'contact_details') {
						$query .= "(";
						$query .= "UPPER(i.name) REGEXP '[[:<:]]{$word}'";
						$query .= " OR UPPER(i.misc) REGEXP '[[:<:]]{$word}'";
						$query .= ")";
					}
				}
				$query .= ")";
			}
			else {
				if($context == 'content') {
					$query .= " AND (";
						$query .= "UPPER(i.title) LIKE '%".mb_strtoupper($keyword)."%'";
						$query .= " OR UPPER(i.introtext) LIKE '%".mb_strtoupper($keyword)."%'";
						$query .= " OR UPPER(i.fulltext) LIKE '%".mb_strtoupper($keyword)."%'";
						$query .= " OR UPPER(i.metakey) LIKE '%{$keyword}%'";
						$query .= " OR UPPER(i.alias) LIKE '%{$keyword}%'";
						$query .= " OR UPPER(menu.`params`) LIKE '%{$keyword}%'";
						if($this->module_params->search_flexicontent_enabled) {
							$query .= " OR flexi.`search_index` LIKE '%{$keyword}%'";
						}
						//commented for prevent slow loading with big databases
						//$query .= "OR fv.value LIKE '%{$keyword}%'";
					$query .= ")";
				}
				else if($context == 'contact_details') {
					$query .= " AND (";
						$query .= "UPPER(i.name) LIKE '%".mb_strtoupper($keyword)."%'";
						$query .= " OR UPPER(i.misc) LIKE '%".mb_strtoupper($keyword)."%'";
						$query .= " OR UPPER(menu.`params`) LIKE '%{$keyword}%'";
					$query .= ")";					
				}
			}
		}		

		//category
		if(JRequest::getVar("category")) {
			$categories = JRequest::getVar("category");
			if($categories[0] != "") {
				if($this->module_params->restsub) {
					foreach($categories as $category) {
						foreach(explode(",", $category) as $mixed) {
							$subs = (array)$this->module_helper->getSubCategories($mixed);
						}
						$categories = array_merge($categories, $subs);
					}
				}
				//added for compatibility with cv multicategories plugin
				if (JPluginHelper::isEnabled('system', 'cwmulticats')) {
					$query .= " AND (
									i.attribs REGEXP 'multicats\":\".*(".implode("|", $categories).")[^\]]\"'
									OR i.catid IN (".implode(",", $categories).")
								)";
								
				}
				else {
					$query .= " AND i.catid IN (".implode(",", $categories).")";
				}
			}
		}

		// Tag search
		if(JRequest::getInt("tag")) {
			$tags = JRequest::getInt("tag");
			if($_GET['match'] == 'all') {
				foreach($tags as $tag) {
					$tag = (int)$tag;
					$type = ($context == "contact_details") ? "com_contact.contact" :  "com_content.article";
					$query .= " AND {$tag} IN (SELECT tag_id FROM #__contentitem_tag_map WHERE content_item_id = i.id AND type_alias = '{$type}')";
				}
			}
			else {
				$query .= " AND (tm.tag_id IN(".implode(",", $tags)."))";
			}
		}
		
		//j2 store tag
		if(JRequest::getInt("j2store_tag")) {
			$query .= " AND tm.tag_id = ".JRequest::getInt("j2store_tag");
		}

		//author
		if(JRequest::getInt("author")) {
			$query .= " AND i.created_by IN (".implode(",", JRequest::getInt("author")).")";
		}
		
		//date
		if(JRequest::getVar("date-from")) {
			$query .= " AND i.created >= '".JRequest::getVar("date-from")." 00:00:00'";
		}
		if(JRequest::getVar("date-to")) {
			$query .= " AND i.created <= '".JRequest::getVar("date-to")." 23:59:59'";
		}
		
		//fields search
		require_once(JPATH_SITE . '/modules/mod_articles_good_search/helper.php');
		$module_helper = new modArticlesGoodSearchHelper;

		foreach($_REQUEST as $param=>$value) {
			preg_match('/^field([0-9]+)$/', $param, $matches);
			$field_id = $matches[1];
			$query_params = JRequest::getVar("field{$field_id}");
			$sub_query = "SELECT DISTINCT item_id FROM #__fields_values WHERE 1";
			
			//text / date
			if(!is_array($query_params) && $query_params != "") {
				$query_params = addslashes($query_params);
				$sub_query .= " AND field_id = {$field_id}";
				$field_params = $module_helper->getCustomField($field_id);
				if($field_params->type == "calendar") {
					$date = \JFactory::getDate($query_params)->setTimezone($timezone);
					$sub = $date->getOffsetFromGmt();
					// get date with timezone offset
					$query_params = date("Y-m-d", strtotime($date->format('Y-m-d H:i:s')) - $sub);
					$sub_query .= " AND value LIKE '%{$query_params}%'";
				}
				else {
					$extra_params = json_decode($field_params->fieldparams);
					$numeric = array('anum', 'integer', 'float');
					if(in_array($extra_params->filter, $numeric)) { // filter as number
						$sub_query .= " AND value = '{$query_params}'";
					}
					else {
						$sub_query .= " AND value LIKE '%{$query_params}%'";
					}
				}
			}
			
			//list values
			if(is_array($query_params) && $query_params[0] != "") {
				$sub_query .= " AND field_id = {$field_id}";
				$sub_query .= " AND (";
				foreach($query_params as $k=>$query_param) {
					$query_param = addslashes($query_param);
					$sub_query .= "value = '{$query_param}'";
					if(($k+1) != count($query_params)) {
						if($_GET['match'] == "all") {
							$sub_query .= " AND ";
						}
						else {
							$sub_query .= " OR ";
						}
					}
				}
				$sub_query .= ")";
			}
			
			//text range / date range
			preg_match('/^field([0-9]+)-from$/', $param, $matches);
			$field_id = $matches[1];
			if(JRequest::getVar("field{$field_id}-from") != "") {
				$sub_query .= " AND field_id = {$field_id}";
				$field_params = $module_helper->getCustomField($field_id);
				$query_params = JRequest::getVar("field{$field_id}-from");
				$query_params = addslashes($query_params);
				if($field_params->type == "calendar") {
					$date_search = new DateTime($query_params, $timezone);
					$query_params = $date_search->format('Y-m-d');
					$sub_query .= " AND value >= '{$query_params} 00:00:00'";
				}
				else {
					if(is_numeric($query_params)) {
						$query_params = trim(preg_replace('/\s+/i', '', $query_params));
					}
					else {
						$query_params = "'" . $query_params . "'";
					}
					$sub_query .= " AND value >= {$query_params}";
				}
			}

			preg_match('/^field([0-9]+)-to$/', $param, $matches);
			$field_id = $matches[1];
			if(JRequest::getVar("field{$field_id}-to") != "") {
				$sub_query .= " AND field_id = {$field_id}";
				$field_params = $module_helper->getCustomField($field_id);
				$query_params = JRequest::getVar("field{$field_id}-to");
				$query_params = addslashes($query_params);
				if($field_params->type == "calendar") {
					$date_search = new DateTime($query_params, $timezone);
					$query_params = $date_search->format('Y-m-d');
					$sub_query .= " AND value <= '{$query_params} 23:59:59'";
				}
				else {
					if(is_numeric($query_params)) {
						$query_params = trim(preg_replace('/\s+/i', '', $query_params));
					}
					else {
						$query_params = "'" . $query_params . "'";
					}
					$sub_query .= " AND value <= {$query_params}";
				}
			}
			
			// Execute query and get item ids
			if($query_params != "" && $query_params[0] != "") {
				$ids = JFactory::getDBO()->setQuery($sub_query)->loadColumn();
				if(count($ids)) {
					$query .= " AND i.id IN(" . implode(",", $ids) . ")";
				}
				else {
					$query .= " AND i.id = 0";
				}
			}
		}
		
		//added for compatibility with radical multifield
		foreach($_REQUEST as $param=>$value) {
			preg_match('/^multifield([0-9]+)-([^-]*)(.*)/i', $param, $matches);
			$field_id = $matches[1];
			$sub_field = $matches[2];
			$isRange = $matches[3] != '' ? true : false;
			if(!$field_id || !$sub_field) continue;
			$field_params = $module_helper->getCustomField($field_id);
			
			$uri_params = JRequest::getVar($param);		
			$sub_query = "SELECT DISTINCT item_id FROM #__fields_values WHERE 1";
			
			//text / date
			if(!is_array($uri_params) && $uri_params != "" && !$isRange) {
				$sub_query .= " AND field_id = {$field_id}";
				if($field_params->type == "calendar") {
					$date_search = new DateTime($uri_params, $timezone);
					$uri_params = $date_search->format('Y-m-d');
					$sub_query .= " AND value LIKE '%{$uri_params}%'";
				}
				else {
					$sub_query .= " AND value REGEXP '\"{$sub_field}\":\"{$uri_params}\"'";
				}
			}
			
			//text range / date range
			if($matches[3] == '-from') {
				$range_query = "SELECT * FROM #__fields_values WHERE field_id = {$field_id}";
				$values = JFactory::getDBO()->setQuery($range_query)->loadObjectList();
				$ids_to_include = array();
				foreach($values as $value) {
					$item_id = $value->item_id;
					$value = json_decode($value->value);
					foreach($value as $val) {
						if($val->{$sub_field} >= $uri_params) { //check for more or equal
							$ids_to_include[] = $item_id;
						}
					}
				}
				$ids_to_include = array_values(array_unique($ids_to_include));
				if(count($ids_to_include)) {
					$sub_query .= " AND item_id IN(" . implode(",", $ids_to_include) . ")";
				}
				else {
					$sub_query .= " AND item_id = 0";
				}
			}
			if($matches[3] == '-to') {
				$range_query = "SELECT * FROM #__fields_values WHERE field_id = {$field_id}";
				$values = JFactory::getDBO()->setQuery($range_query)->loadObjectList();
				$ids_to_include = array();
				foreach($values as $value) {
					$item_id = $value->item_id;
					$value = json_decode($value->value);
					foreach($value as $val) {
						if($val->{$sub_field} <= $uri_params
							&& $val->{$sub_field} != ''
						) { //check for less or equal
							$ids_to_include[] = $item_id;
						}
					}
				}
				$ids_to_include = array_values(array_unique($ids_to_include));
				if(count($ids_to_include)) {
					$sub_query .= " AND item_id IN(" . implode(",", $ids_to_include) . ")";
				}
				else {
					$sub_query .= " AND item_id = 0";
				}
			}
			
			// Execute query and get item ids
			if($uri_params != "" && $uri_params[0] != "") {
				$ids = JFactory::getDBO()->setQuery($sub_query)->loadColumn();
				if(count($ids)) {
					$query .= " AND i.id IN(" . implode(",", $ids) . ")";
				}
				else {
					$query .= " AND i.id = 0";
				}
			}
		}
		
		//added for compatibility with repeatable field
		foreach($_REQUEST as $param=>$value) {
			preg_match('/^repeatable([0-9]+)-([^-]*)(.*)/i', $param, $matches);
			$field_id = $matches[1];
			$sub_field_number = $matches[2];
			$isRange = $matches[3] != '' ? true : false;
			if(!$field_id || $sub_field_number === NULL) continue;
			$field_params = $module_helper->getCustomField($field_id);
			
			$uri_params = JRequest::getVar($param);		
			$sub_query = "SELECT DISTINCT item_id FROM #__fields_values WHERE 1";
			
			$sub_field_values = json_decode($field_params->fieldparams);
			$sub_field_name = $sub_field_values->fields->{"fields".$sub_field_number}->fieldname;
			
			//text / date
			if(!is_array($uri_params) && $uri_params != "" && !$isRange) {
				$sub_query .= " AND field_id = {$field_id}";
				if($field_params->type == "calendar") {
					$date_search = new DateTime($uri_params, $timezone);
					$uri_params = $date_search->format('Y-m-d');
					$sub_query .= " AND value LIKE '%{$uri_params}%'";
				}
				else {
					$uri_params = trim(json_encode($uri_params), '"');
					$uri_params = str_replace("\\", "\\\\\\\\", $uri_params);
					$sub_query .= " AND value REGEXP '\"{$sub_field_name}\":\"[^\"]*{$uri_params}[^\"]*\"'";
				}
			}
			
			//text range / date range
			if($matches[3] == '-from') {
				$range_query = "SELECT * FROM #__fields_values WHERE field_id = {$field_id}";
				$values = JFactory::getDBO()->setQuery($range_query)->loadObjectList();
				$ids_to_include = array();
				foreach($values as $value) {
					$item_id = $value->item_id;
					$value = json_decode($value->value);
					foreach($value as $val) {
						if($val->{$sub_field_name} >= $uri_params) { //check for more or equal
							$ids_to_include[] = $item_id;
						}
					}
				}
				$ids_to_include = array_values(array_unique($ids_to_include));
				if(count($ids_to_include)) {
					$sub_query .= " AND item_id IN(" . implode(",", $ids_to_include) . ")";
				}
				else {
					$sub_query .= " AND item_id = 0";
				}
			}
			if($matches[3] == '-to') {
				$range_query = "SELECT * FROM #__fields_values WHERE field_id = {$field_id}";
				$values = JFactory::getDBO()->setQuery($range_query)->loadObjectList();
				$ids_to_include = array();
				foreach($values as $value) {
					$item_id = $value->item_id;
					$value = json_decode($value->value);
					foreach($value as $val) {
						if($val->{$sub_field_name} <= $uri_params
							&& $val->{$sub_field_name} != ''
						) { //check for less or equal
							$ids_to_include[] = $item_id;
						}
					}
				}
				$ids_to_include = array_values(array_unique($ids_to_include));
				if(count($ids_to_include)) {
					$sub_query .= " AND item_id IN(" . implode(",", $ids_to_include) . ")";
				}
				else {
					$sub_query .= " AND item_id = 0";
				}
			}
			
			// Execute query and get item ids
			if($uri_params != "" && $uri_params[0] != "") {
				$ids = JFactory::getDBO()->setQuery($sub_query)->loadColumn();
				if(count($ids)) {
					$query .= " AND i.id IN(" . implode(",", $ids) . ")";
				}
				else {
					$query .= " AND i.id = 0";
				}
			}
		}

		return $query;
	}
	
	function getPagination() {
		jimport('joomla.html.pagination');
		$pagination = new JPagination($this->total_items, $this->limitstart, $this->limit);
		foreach($_REQUEST as $param=>$value) {
			if(in_array($param, Array("id", "start", "option", "view", "task"))) continue;
			if(is_array($value)) {
				foreach($value as $k=>$val) {
					$pagination->setAdditionalUrlParam($param . "[{$k}]", $val);
				}
			}
			else {
				$pagination->setAdditionalUrlParam($param, $value);
			}
		}
		return $pagination;
	}
	
	function execPlugins(&$item, $context = 'content') {
		$app = JFactory::getApplication('site');
		$params = $app->getParams();
		$dispatcher = JEventDispatcher::getInstance();
		$item->event   = new stdClass;

		// Old plugins: Ensure that text property is available
		$item->text = $item->introtext;
		
		if($context == 'contact_details') {
			$context = 'com_contact.contact';
		}
		else {
			$context = 'com_content.category';
		}
		
		JPluginHelper::importPlugin('content');
		$dispatcher->trigger('onContentPrepare', array($context, &$item, &$item->params, 0));

		// Old plugins: Use processed text as introtext
		$item->introtext = $item->text;
		
		$item->params = new JRegistry($item->attribs);
		
		$results = $dispatcher->trigger('onContentBeforeDisplay', array($context, &$item, &$item->params, 0));
		$item->event->beforeDisplayContent = trim(implode("\n", $results));
		
		$results = $dispatcher->trigger('onContentAfterTitle', array($context, &$item, &$item->params, 0));
		$item->event->afterDisplayTitle = trim(implode("\n", $results));

		$results = $dispatcher->trigger('onContentAfterDisplay', array($context, &$item, &$item->params, 0));
		$item->event->afterDisplayContent = trim(implode("\n", $results));
	}
	
	function getAuthorById($id) {
		$db = JFactory::getDBO();
		$query = "SELECT * FROM #__users WHERE id = {$id}";
		$db->setQuery($query);
		return $db->loadObject();
	}
	
	function getCategoryById($id) {
		$db = JFactory::getDBO();
		$query = "SELECT * FROM #__categories WHERE id = {$id}";
		$db->setQuery($query);
		return $db->loadObject();
	}
	
	function getItemCategories($aItem, $context = 'content') {
		$aCategories = array();
		$catids = array();
		//added for compatibility with cv multicategories plugin
		if (JPluginHelper::isEnabled('system', 'cwmulticats')) {
			//$catids = JFactory::getDBO()->setQuery("SELECT catid FROM #__content_multicats WHERE content_id = {$aItem->id} ORDER BY ordering ASC")->loadColumn();
			$params = json_decode($aItem->attribs);
			$catids = json_decode($params->multicats);
		}
		else {
			$catids = array($aItem->catid);
		}
		if(!count($catids)) {
			$catids = array($aItem->catid);
		}
		$catids = array_unique($catids);
		require_once(JPATH_SITE . '/components/com_content/helpers/route.php');
		foreach($catids as $id) {
			$category = $this->getCategoryById($id);
			if($context == 'contact_details') {
				JLoader::register('ContactHelperRoute', JPATH_SITE . '/components/com_contact/helpers/route.php');
				$category->link = JRoute::_(ContactHelperRoute::getCategoryRoute($category->id));
			}
			else {
				$category->link = JRoute::_(ContentHelperRoute::getCategoryRoute($category->id));
			}
			$aCategories[] = $category;
		}
		return $aCategories;
	}
	
	function saveSearchSession() {
		if(!$_GET['gsearch']) return;
		JFactory::getSession()->set("SaveSearchValues", $_GET);
	}
	
	function saveSearchStats() {
		$this->searchStatsTableCreate();
		$data = json_decode($_GET['data_stats']);
		$keyword = $data[0]->title;
		$search_link = $data[0]->link;
		
		//save keyword
		$query = "SELECT search_count FROM #__content_search_stats WHERE url = '{$search_link}'";
		$count = intval(JFactory::getDBO()->setQuery($query)->loadResult());
		$config = JFactory::getConfig();
		$tzoffset = $config->get('offset');
		$date = JFactory::getDate('', $tzoffset)->format("Y-m-d H:i:s");
		if($count) {
			$query = "UPDATE #__content_search_stats SET search_count = (search_count + 1), last_search_date = '{$date}' WHERE url = '{$search_link}'";
			JFactory::getDBO()->setQuery($query)->query();
			$query = "SELECT id FROM #__content_search_stats WHERE url = '{$search_link}'";
			$keyword_id = JFactory::getDBO()->setQuery($query)->loadResult();
		} else {
			$query = "INSERT INTO #__content_search_stats VALUES ('', '{$keyword}', '{$search_link}', '{$date}', 1)";
			JFactory::getDBO()->setQuery($query)->query();
			$keyword_id = JFactory::getDBO()->insertid();
		}
		//save user
		$user = JFactory::getUser();
		$query = "SELECT search_count FROM #__content_search_stats_users WHERE keyword_id = {$keyword_id} AND user_id = {$user->id}";
		$count = intval(JFactory::getDBO()->setQuery($query)->loadResult());
		$ip_address = $_SERVER['REMOTE_ADDR'];
		if($count) {
			$query = "UPDATE #__content_search_stats_users SET search_count = (search_count + 1), last_search_date = '{$date}', ip_address = '{$ip_address}' WHERE keyword_id = {$keyword_id} AND user_id = {$user->id}";
			JFactory::getDBO()->setQuery($query)->query();
		} else {
			$query = "INSERT INTO #__content_search_stats_users VALUES ('', {$user->id}, {$keyword_id}, '{$date}', 1, '{$ip_address}')";
			JFactory::getDBO()->setQuery($query)->query();
		}		
	}

	function getStatsList($total = false) {
		$this->searchStatsTableCreate();
		$db = JFactory::getDBO();
		$limitstart = $this->limit;
	
		if($total) {
			$query = "SELECT COUNT(DISTINCT id) FROM #__content_search_stats";
		}
		else {
			$query = "SELECT * FROM #__content_search_stats";
			$order = addslashes(JRequest::getVar("orderby", "last_search_date"));
			$query .= " ORDER BY {$order} DESC";
		}
		
		if($total) {
			$db->setQuery($query);	
			return $db->loadResult();
		}
		else {
			$db->setQuery($query, JRequest::getInt("limitstart", 0), 10);
			return $db->loadObjectList();
		}
	}
	
	function getStatsListPagination() {
		$total_items = $this->getStatsList(true);
		jimport('joomla.html.pagination');
		$pagination = new JPagination($total_items, JRequest::getInt("limitstart", 0), 10);
		foreach($_REQUEST as $param=>$value) {
			if(in_array($param, Array("id", "start", "option", "view", "task", "limit", "featured"))) continue;
			if(is_array($value)) {
				foreach($value as $k=>$val) {
					$pagination->setAdditionalUrlParam($param . "[{$k}]", $val);
				}
			}
			else {
				$pagination->setAdditionalUrlParam($param, $value);
			}
		}
		return $pagination;
	}	

	function getStatsKeywordList($total = false) {
		$this->searchStatsTableCreate();
		$db = JFactory::getDBO();
		$limitstart = $this->limit;	
		$keyword_id = JRequest::getInt("id");
	
		if($total) {
			$query = "SELECT COUNT(DISTINCT id) FROM #__content_search_stats_users WHERE keyword_id = {$keyword_id}";
		}
		else {
			$query = "SELECT * FROM #__content_search_stats_users WHERE keyword_id = {$keyword_id}";
			$order = addslashes(JRequest::getVar("orderby", "last_search_date"));
			$query .= " ORDER BY {$order} DESC";
		}
		
		if($total) {
			$db->setQuery($query);	
			return $db->loadResult();
		}
		else {
			$db->setQuery($query, JRequest::getInt("limitstart", 0), 10);
			return $db->loadObjectList();
		}
	}
	
	function getStatsKeywordListPagination() {
		$total_items = $this->getStatsKeywordList(true);
		jimport('joomla.html.pagination');
		$pagination = new JPagination($total_items, JRequest::getInt("limitstart", 0), 10);
		foreach($_REQUEST as $param=>$value) {
			if(in_array($param, Array("id", "start", "option", "view", "task", "limit", "featured"))) continue;
			if(is_array($value)) {
				foreach($value as $k=>$val) {
					$pagination->setAdditionalUrlParam($param . "[{$k}]", $val);
				}
			}
			else {
				$pagination->setAdditionalUrlParam($param, $value);
			}
		}
		return $pagination;
	}
	
	function searchStatsTableCreate() {	
		$query = "CREATE TABLE IF NOT EXISTS `#__content_search_stats` (";
			$query .= "`id` int(21) NOT NULL AUTO_INCREMENT PRIMARY KEY,";
			$query .= "`keyword` varchar(255) NOT NULL,";
			$query .= "`url` tinytext NOT NULL,";
			$query .= "`last_search_date` varchar(255) NOT NULL,";
			$query .= "`search_count` int(11) NOT NULL";
		$query .= ") ENGINE=MyISAM  DEFAULT CHARSET=utf8;";
		JFactory::getDBO()->setQuery($query)->query();
		
		$query = "CREATE TABLE IF NOT EXISTS `#__content_search_stats_users` (";
			$query .= "`id` int(21) NOT NULL AUTO_INCREMENT PRIMARY KEY,";
			$query .= "`user_id` int(21) NOT NULL,";
			$query .= "`keyword_id` int(21) NOT NULL,";
			$query .= "`last_search_date` varchar(255) NOT NULL,";
			$query .= "`search_count` int(21) NOT NULL,";
			$query .= "`ip_address` varchar(255) NOT NULL";
		$query .= ") ENGINE=MyISAM  DEFAULT CHARSET=utf8;";
		JFactory::getDBO()->setQuery($query)->query();
	}

	function getItemsSP() {
		$this->sp_items_count = 0;
		$this->sp_items = array();	

		$this->sp_items_count = $this->getQuerySP(true);
		$items = $this->getQuerySP(false);
		$this->sp_items = $items;
	}
	
	function getQuerySP($total = false) {
		$db = JFactory::getDBO();
		if($total) {
			$query = "SELECT COUNT(i.id) FROM #__sppagebuilder as i";
		}
		else {
			$query = "SELECT 'sppagebuilder' as gsearch_item_type, i.* FROM #__sppagebuilder AS i";
		}
		
		$query .= " LEFT JOIN #__menu as menu ON menu.`link` = CONCAT('index.php?option=com_sppagebuilder&view=page&id=', i.id)";
		
		$query .= " WHERE i.published = 1 AND i.extension_view = 'page'";
		
		//language filter
		$language = JFactory::getLanguage();
		$defaultLang = $language->getDefault();
		$currentLang = $language->getTag();
		$query .= " AND i.language IN ('*', '{$currentLang}')";
		
		//keyword
		if(JRequest::getVar("keyword")) {
			$keyword = strtoupper(JRequest::getVar("keyword"));
			$keyword = addslashes($keyword);
			$keyword = str_replace("/", "\\\\\\\/", $keyword);
			$keyword = str_replace("(", "\\\\(", $keyword);
			$keyword = str_replace(")", "\\\\)", $keyword);
			$keyword = str_replace("*", "\\\\*", $keyword);
			if($_GET['match'] == 'any') {
				$query .= " AND (";
				foreach(explode(" ", $keyword) as $k=>$word) {
					$query .= $k > 0 ? " OR " : "";
					$query .= "UPPER(title) LIKE '%{$word}%'";
					$query .= " OR UPPER(text) LIKE '%{$word}%'";
					$query .= " OR UPPER(menu.`params`) LIKE '%{$word}%'";
				}
				$query .= ")";
			}
			else {
				$query .= " AND (
						UPPER(i.title) LIKE '%{$keyword}%'
						OR UPPER(i.text) LIKE '%{$keyword}%'
				";
				$query .= " OR UPPER(menu.`params`) LIKE '%{$keyword}%'";
				$query .= ")";
			}
		}
		
		//category restriction
		if($this->module_params->restrict) {
			$module_params_native = $this->module_helper->getModuleParams($this->module_id, true);
			$category_restriction = $this->module_helper->getCategories(0, $module_params_native);
			if(count($category_restriction)) {
				$ids = Array();
				foreach($category_restriction as $c) {
					$ids[] = $c->id;
				}
				$query .= " AND i.catid IN (".implode(",", $ids).")";
			}			
		}
		
		//category search
		if(JRequest::getVar("category")) {
			$categories = JRequest::getVar("category");
			if($categories[0] != "") {
				if($this->module_params->restsub) {
					foreach($categories as $category) {
						foreach(explode(",", $category) as $mixed) {
							$subs = (array)$this->module_helper->getSubCategories($mixed);
						}
						$categories = array_merge($categories, $subs);
					}
				}
				$query .= " AND i.catid IN (".implode(",", $categories).")";
			}
		}
		
		//language filter
		$language = JFactory::getLanguage();
		$defaultLang = $language->getDefault();
		$currentLang = $language->getTag();
		$query .= " AND i.language IN ('*', '{$currentLang}')";
		
		//access filter
		$query .= " AND i.access IN(".implode(',', JFactory::getUser()->getAuthorisedViewLevels()).")";

		if(isset($_GET['debug'])) {
			echo "SP Query:<br />";
			echo $query . "<hr />";
		}
	
		if($total) {
			$db->setQuery($query);
			$count = $db->loadResult();
			return $count;
		}
		else {
			$db->setQuery($query);
			return $db->loadObjectList();
		}		
	}
	
	function sortMixedItems($items) {
		$featuredFirst = false;
		if($this->module_params->include_featured == "First") {
			$featuredFirst = true;
		}
		
		$default_ordering = $featuredFirst ? 'featured' : $this->module_params->ordering_default;
		$orderby = JRequest::getVar("orderby", $default_ordering);
		$orderto = JRequest::getVar("orderto", $this->module_params->ordering_default_dir);
		
		switch($orderby) {
			case "title" :
			case "alias" :
			case "created" :
			case "publish_up" :
			case "category" :
			case "hits" :
			case "featured" :
			case "rand" :
			case "id" :
			default :
				$sortKey = $orderby;
		}

		usort($items, function($v1, $v2) use ($sortKey, $orderto) {
			if(!property_exists($v1, $sortKey)) return 0;
			if(!property_exists($v2, $sortKey)) return 0;
			if ($v1->{$sortKey} == $v2->{$sortKey}) return 0;
			if($orderto == 'asc') {
				return ($v1->{$sortKey} < $v2->{$sortKey}) ? -1: 1;			
			}
			else {
				return ($v1->{$sortKey} > $v2->{$sortKey}) ? -1: 1;
			}
		});
		
		return $items;
	}
}

?>