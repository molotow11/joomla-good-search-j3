<?php

/**
 * @package     Articles Good Search
 *
 * @copyright   Copyright (C) 2017 Joomcar extensions. All rights reserved.
 * @license     GNU General Public License version 2 or later.
 */

defined('_JEXEC') or die;

JLoader::register('FieldsHelper', JPATH_ADMINISTRATOR . '/components/com_fields/helpers/fields.php');

$document = JFactory::getDocument();
$lang = JFactory::getLanguage();
$lang->load("mod_articles_good_search");

require_once(JPATH_SITE . "/plugins/system/plg_articles_good_search/models/com_content/model.php");
$model = new ArticlesModelGoodSearch;

$model->limit = JRequest::getInt("limit", $model->module_params->items_limit); //set items per page;
$columns = JRequest::getInt('columned', $model->module_params->template_columns);
if(!$columns) $columns = 1;

$items = $model->getItems();

if($model->module_params->page_heading != "") {
	$document->setTitle($model->module_params->page_heading);
}

JHtml::_('bootstrap.framework');
$document->addStyleSheet(JURI::root(true) . '/media/jui/css/icomoon.css');

?>

<script>
		jQuery(document).ready(function() {
			jQuery.fn.highlight = function (str, className) {
				var regex = new RegExp(str, "gi");
				return this.each(function () {
					jQuery(this).contents().filter(function() {
						return this.nodeType == 3 && regex.test(this.nodeValue);
					}).replaceWith(function() {
						return (this.nodeValue || "").replace(regex, function(match) {
							return "<span style='background-color: #ffff00; font-weight: bold; padding: 2px 5px;' class=\"" + className + "\">" + match + "</span>";
						});
					});
				});
			};
			<?php if(JRequest::getVar("keyword", "") != "") : ?>
			jQuery(".blog-gsearch *").highlight("<?php echo JRequest::getVar("keyword", ""); ?>", "highlight");
			<?php endif; ?>
		});
	
</script>

<?php

// switch to table template
if(JRequest::getVar("search_layout", $model->module_params->results_template) == "table") {
	require_once(__DIR__ . '/gsearch_table.php');
	return;
}

?>

<style>
	.blog-gsearch img { max-width: 100%; }
	.blog-gsearch .pagination { text-align: center; float: none; width: 100%; }
	.blog-gsearch .item { margin-top: 30px; }	
	.blog-gsearch .item .item-info { font-size: 12px; margin: 20px 0 20px 0; padding-bottom: 10px; border-bottom: 1px solid #eee; }
	.blog-gsearch .item .item-info ul { list-style: none; margin: 0; padding: 0; }
	.blog-gsearch .item .item-info li { display: inline-block; position: relative; margin-right: 15px; }
	.blog-gsearch .item.unmarged { margin-left: 0px !important; }
	<?php if($model->module_params->image_width) { ?>
		div.gsearch-results-<?php echo $model->module_id; ?> img { 
			max-width: <?php echo str_replace("px", "", $model->module_params->image_width); ?>px !important; 
			height: auto !important; 
		}
	<?php } ?>
	<?php echo $model->module_params->styles; ?>

	.gsearch-results-<?php echo $model->module_id; ?> .item { word-break: break-word; }
	.gsearch-results-<?php echo $model->module_id; ?>.columned .itemlist {
		<?php if($columns > 1) { ?>
		display: grid;
		grid-gap: 10px;
		grid-template-columns: <?php for($i = 0; $i < $columns; $i++) { echo "1fr "; } ?>;
		<?php } ?>
	}
	
	@media (max-width: 798px) {
		.gsearch-results-<?php echo $model->module_id; ?>.columned .itemlist {
			grid-template-columns: 1fr !important;
		}
	}
</style>

<div id="gsearch-results" class="blog blog-gsearch gsearch-results-<?php echo $model->module_id; ?><?php if($columns > 1) { echo ' columned'; } ?>" itemscope itemtype="https://schema.org/Blog">
	<div class="page-header" style="display: inline-block;">
		<h3>
			<?php
				if(!$model->module_params->resultf) {
					$model->module_params->resultf = JText::_("MOD_AGS_RESULT_PHRASE_DEFAULT");
				}
				echo (count($items) ? JText::_($model->module_params->resultf) . " ({$model->total_items})" : JText::_($model->module_params->noresult)); 
			?>
		</h3>
	</div>
	
	<?php if(count($items)) { ?>
	<div class="gsearch-toolbox" style="float: right; margin-top: 12px;">
		<?php if($model->module_params->layout_show) { ?>
		<div class="gsearch-layout">
		<?php require(dirname(__FILE__). '/gsearch_layout.php'); ?>
		</div>
		<?php } ?>
		<?php if($model->module_params->ordering_show) { ?>
		<div class="gsearch-sorting">
		<?php require(dirname(__FILE__). '/gsearch_sorting.php'); ?>
		</div>
		<?php } ?>
		<div style="clear: both;"></div>
	</div>
	<?php } ?>
	
	<div style="clear: both;"></div>
	
	<div class="itemlist">
	<?php 
		$rows_counter = 0;
		foreach($items as $items_counter => $item) { 
			// extra item types
			if(property_exists($item, 'gsearch_item_type')) {
				$item_type = $item->gsearch_item_type;
				require(dirname(__FILE__). "/gsearch_blog_item_{$item_type}.php");
				continue;
			}
			
			// standard items and j2store
			$item->slug = $item->alias ? ($item->id . ':' . $item->alias) : $item->id;
			$item->parent_slug = ($item->parent_alias) ? ($item->parent_id . ':' . $item->parent_alias) : $item->parent_id;
			if ($item->parent_alias == 'root') {
				$item->parent_slug = null;
			}
			$item->catslug = $item->category_alias ? ($item->catid . ':' . $item->category_alias) : $item->catid;

			if($model->module_params->results_template == "") {
				$model->module_params->results_template = "standard";
			}
			if($model->module_params->results_template == "standard"
				|| $model->module_params->results_template == "table"
			) {
				require(dirname(__FILE__). '/gsearch_blog_item.php');
			}
			else {
				require(dirname(__FILE__). "/gsearch_blog_item_{$model->module_params->results_template}.php"); 
			}

		}
	?>
	</div>
	
	<div style="clear: both;"></div>
	<?php require(dirname(__FILE__). '/gsearch_paging.php'); ?>
</div>