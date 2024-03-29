<?php
/**
 * @package     Articles Good Search
 *
 * @copyright   Copyright (C) 2017 Joomcar extensions. All rights reserved.
 * @license     GNU General Public License version 2 or later.
 */

// no direct access
defined('_JEXEC') or die('Restricted access');

$active = JRequest::getVar("field".$field->id, array());
$field_params = json_decode($field->instance->fieldparams);
$values = $field_params->options;
if($field->instance->type == "integer") {
	$values = Array();
	$range = range($field_params->first, $field_params->last, $field_params->step);
	foreach($range as $val) {
		$tmp = new stdClass;
		$tmp->value = $val;
		$tmp->name = $val;
		$values[] = $tmp;
	}
}
if(!$values) {
	//try to get a values from text (autofill)
	$values = Array();
	$text_values = $helper->getFieldValuesFromText($field->id, "text", $module->id);
	foreach($text_values as $val) {
		$tmp = new stdClass;
		$tmp->value = explode("::", $val)[0];
		$tmp->name = explode("::", $val)[1] != "" ? explode("::", $val)[1] : $val;
		$values[] = $tmp;
	}
}
?>

<div class="gsearch-field-checkboxes custom-field">	
	<h3>
		<?php echo JText::_("{$field->instance->label}"); ?>
	</h3>
	
	<div class="checkboxes-container form-check">
	<?php foreach($values as $val) { ?>
		<label class="form-check-label">
			<input class="inputbox form-check-input" type="checkbox" name="field<?php echo $field->id; ?>[]" value="<?php echo $val->value; ?>"
				<?php if(in_array($val->value, $active)) { ?> 
				checked="checked"
				<?php } ?>
			/>
			<?php echo JText::_($val->name); ?> 
		</label>
	<?php } ?>
	</div>
</div>