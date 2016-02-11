<?php
/**
 * Decorator for the Managedfile widget for Zend_Form.
 *
 * @category    Curry CMS
 * @package     Curry
 * @subpackage  Managedfile
 * @author      Jose F. D'Silva
 *
 */
class Project_Form_Decorator_Managedfile extends Zend_Form_Decorator_Abstract
{
    public function render($content)
    {
        $element = $this->getElement();
        
        // apply this decorator to Managedfile elements only.
        if (! $element instanceof Project_Form_Element_Managedfile) {
            return $content;
        }
        
        $view = $element->getView();
        if (! $view instanceof Zend_View_Interface) {
            // do nothing if no view is present.
            return $content;
        }
        
        // element's FQN
        $name = $element->getFullyQualifiedName();
        $fid = $element->getValue();
        $filepath = ((null !== $fid) && is_int($fid)) ? Managedfile::getManagedFilepath($fid) : '';
        
        $attribs = $element->getAttribs();
        $markup = $view->formText($name, $filepath, $attribs);
        $separator = $this->getSeparator();
        switch ($this->getPlacement()) {
        	case self::PREPEND:
        	    $ret = $markup . $separator . $content;
        	    break;
        	case self::APPEND:
        	default:
        	    $ret = $content . $separator . $markup;
        	    break;
        }
        
        return $ret;
    }
}