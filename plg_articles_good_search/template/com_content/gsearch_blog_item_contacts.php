<?php

/**
 * @package     Articles Good Search
 *
 * @copyright   Copyright (C) 2017 Joomcar extensions. All rights reserved.
 * @license     GNU General Public License version 2 or later.
 */

defined('_JEXEC') or die;

JLoader::register('ContactHelperRoute', JPATH_SITE . '/components/com_contact/helpers/route.php');
$item->slug = $item->alias ? ($item->id . ':' . $item->alias) : $item->id;

if(class_exists('FieldsHelper')) {
	$fields = FieldsHelper::getFields('com_contact.contact', $item, true);
}
else {
	$fields = array();
}
$tmp = new stdClass;
foreach($fields as $field) {
	$name = $field->name;
	$tmp->{$name} = $field;
}
$fields = $tmp;
//you can call some field with $fields->{"name"}->title and $fields->{"name"}->value
//e.g.
//echo $fields->{"test1"}->title . ' - ' .  $fields->{"test1"}->value;

$model->execPlugins($item, 'contact_details');

?>

<div class="<?php echo $item_type; ?> item<?php echo $item->featured ? ' featured' : ''; ?> <?php if($columns > 1 && ($items_counter % $columns == 0)) { echo 'unmarged'; } ?> <?php if($columns > 1) { echo 'span' . 12 / $columns; } ?>" itemprop="blogPost" itemscope itemtype="https://schema.org/BlogPosting">
	<h3 itemprop="name" class="item-title">
		<a href="<?php echo JRoute::_(ContactHelperRoute::getContactRoute($item->slug, $item->catid)); ?>" itemprop="url">
			<?php echo $item->name; ?>
		</a>
	</h3>
	
	<?php echo $item->event->afterDisplayTitle; ?>
	<?php echo $item->event->beforeDisplayContent; ?>
	
	<?php 
		$image = "";
		if($item->image != "") {
			$image = $item->image;
		}
		else if($model->module_params->image_empty != -1 && $model->module_params->image_empty != "") {
			$image = JURI::root() . "images/" . $model->module_params->image_empty;
		}
	?>
	
	<?php if($image != "") { ?>
	<div class="item-image">
		<a href="<?php echo JRoute::_(ContactHelperRoute::getContactRoute($item->slug, $item->catid)); ?>">
			<?php echo JHtml::_(
				'image',
				$image,
				$item->name,
				array('class' => 'contact-thumbnail img-thumbnail')
			); ?>
		</a>
	</div>
	<?php } ?>
	
	<div style="clear: both;"></div>
	
	<?php if($model->module_params->show_readmore) { ?>
	<div class="item-readmore">
		<a class="btn btn-secondary" href="<?php echo JRoute::_(ContentHelperRoute::getArticleRoute($item->slug, $item->catid, $item->language)); ?>"><?php echo JText::_('MOD_AGS_ITEM_READMORE'); ?></a>
	</div>
	<?php } ?>
	
	<div style="clear: both;"></div>
	
	<?php if($model->module_params->show_info) { ?>
	<div class="item-info">
		<ul>
			<li class="createdby hasTooltip" itemprop="author" itemscope="" itemtype="http://schema.org/Person" title="" data-original-title="Written by">
				<i class="icon icon-user"></i>
				<span itemprop="name"><?php echo $model->getAuthorById($item->created_by)->name; ?></span>
			</li>
			<li class="category-name hasTooltip" title="" data-original-title="Category">
				<i class="icon icon-folder"></i>
				<?php foreach($model->getItemCategories($item, 'contact_details') as $category) { ?>
				<a href="<?php echo $category->link; ?>">
					<span itemprop="genre">
						<?php echo $category->title; ?>
					</span>
				</a>				
				<?php } ?>
			</li>
			<li class="created">
				<i class="icon icon-clock"></i>
				<time datetime="<?php echo $item->created; ?>" itemprop="dateCreated">
					<?php echo JText::_('MOD_AGS_ITEM_CREATED'); ?> 
					<?php 
						setlocale(LC_ALL, JFactory::getLanguage()->getLocale());
						$date_format = explode("::", $model->module_params_native->get('date_format', '%e %b %Y::d M yyyy'))[0];
						if(strpos(PHP_OS, 'WIN') !== false) {
							$date_format = str_replace("%e", "%#d", $date_format);
						}
						$date = strftime($date_format, strtotime($item->created));
						if(function_exists("mb_convert_case")) {
							$date = mb_convert_case($date, MB_CASE_TITLE, 'UTF-8');
						}
						echo $date;
					?>		
				</time>
			</li>
			<li class="hits">
					<i class="icon icon-eye"></i>
					<meta itemprop="interactionCount" content="UserPageVisits:<?php echo $item->hits; ?>">
					<?php echo JText::_('MOD_AGS_ITEM_HITS'); ?> <?php echo $item->hits; ?>
			</li>
		</ul>
	</div>
	<?php } ?>
	
	<?php echo $item->event->afterDisplayContent; ?>
	<div style="clear: both;"></div>
</div>
<?php if(($items_counter + 1) % $columns == 0) { ?>
<div style="clear: both;"></div>
<?php } ?>