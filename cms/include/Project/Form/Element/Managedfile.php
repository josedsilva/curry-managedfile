<?php
/**
 * Managedfile widget for Zend_Form.
 *
 * @category    Curry CMS
 * @package     Curry
 * @subpackage  Managedfile
 * @author      Jose F. D'Silva
 *
 */
class Project_Form_Element_Managedfile extends Curry_Form_Element_Filebrowser
{
    protected $_fid;
    protected $_filepath;
    protected $_finderOptions;
    
    public function __construct($spec, $options = null)
    {
        // use decorators defined in Project/Form/Decorator/ as default.
        $this->addPrefixPath('Project_Form_Decorator', 'Project/Form/Decorator/', 'decorator');
        parent::__construct($spec, $options);
    }
    
    public function loadDefaultDecorators()
    {
        if ($this->loadDefaultDecoratorsIsDisabled()) {
            return $this;
        }
        
        $decorators = $this->getDecorators();
        if (empty($decorators)) {
            $this->addDecorator('Managedfile')
                ->addDecorator('Errors')
                ->addDecorator('Description', array('tag' => 'p', 'class' => 'description'))
                ->addDecorator('HtmlTag', array(
                    'tag' => 'dd',
                    'id' => $this->getName().'-element'
                ))
                ->addDecorator('Label', array('tag' => 'dt'));
        }
        
        return $this;
    }
    
    /**
     * Override attributes to append managedfile class.
     *
     * @return array
     */
    public function getAttribs()
    {
        $attribs = parent::getAttribs();
        $class = isset($attribs['class']) ? array($attribs['class']) : array('filebrowser');
        $class[] = 'managedfile';
        $attribs['class'] = join(" ", $class);
        return $attribs;
    }
    
    public function setValue($value)
    {
        if ((null === $value) || ('' === $value)) {
            $this->_fid = null;
            $this->_filepath = '';
            $value = null;
        } elseif (is_numeric($value)) {
            $this->_fid = (int) $value;
            $this->_filepath = Managedfile::getManagedFilepath($this->_fid);
        } elseif (is_string($value)) {
            $this->_filepath = (string) $value;
            $this->_fid = Managedfile::getManagedFid($this->_filepath, true);
        }
    }
    
    public function getValue()
    {
        return $this->_fid;
    }
    
    // override
    public function setFinderOptions($options)
    {
        $this->_finderOptions = $options;
    }
    
    public function render(Zend_View_Interface $view = null)
    {
        parent::setFinderOptions(array('action' => 'admin.php?module=Common_Backend_Managedfile_FileBrowser') + (array)$this->_finderOptions);
        return parent::render($view);
    }
    
}