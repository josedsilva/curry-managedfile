<?php
/**
 * Browse the filesystem and update the Managedfile table based on the following events:
 * file/dir rename, file/dir move, file/dir delete, file upload
 *
 * @category   Curry CMS
 * @package    Managedfile
 * @author     Jose F. D'Silva
 */
class Common_Backend_Managedfile_FileBrowser extends Curry_Backend_FileBrowser
{
    /** {@inheritdoc} */
    public static function getName()
    {
        return "Managedfile browser";
    }
    
    /**
     * Convert an absolute path to relative path, offset from the wwwPath folder.
     * @param string $absPath
     * @param string $stripPath
     */
    public static function absoluteToRelativePath($absPath, $stripPath = null)
    {
        if (null === $stripPath) {
            $stripPath = Curry_Core::$config->curry->wwwPath.DIRECTORY_SEPARATOR;
        }
        
        return str_replace($stripPath, '', $absPath);
    }
    
    /** {@inheritdoc} */
    public function showMain()
    {
        $this->addMainContent($this->getFinder());
        $this->addMainContent('<script>$(".finder.managedfiles").finder({action: \''.(string)url('', array('module')).'\'});</script>');
    }
    
    /**
     * Override the Main view.
     *
     * @return string
     */
    public function getFinder()
    {
        if (isPost() && isset($_REQUEST['action'])) {
            try {
                // call instance method
                $method = 'action'.$_REQUEST['action'];
                if (!method_exists($this, $method)) {
                    throw new Curry_Exception('Action does not exist.');
                }
                $contentType = isset($_GET['iframe']) ? 'text/html' : 'application/json';
                Curry_Application::returnJson($this->$method($_REQUEST), "", $contentType);
            } catch(Exception $e) {
                if (isAjax()) {
                    $this->returnJson(array('status' => 0, 'error' => $e->getMessage()));
                } else {
                    $this->addMessage($e->getMessage(), self::MSG_ERROR);
                }
            }
        }
        
        $template = Curry_Twig_Template::loadTemplateString(<<<TPL
{% spaceless %}
<div class="finder managedfiles">
  {% if selection %}
  <input type="hidden" name="selection" value="{{selection}}" />
  {% endif %}
  <div class="finder-overlay"><p></p></div>
  <div class="wrapper">
  {% for path in paths %}
  <ul class="folder {{path.IsRoot?'root':''}}" data-finder='{"path":"{{path.Path}}","action":"{{path.UploadUrl}}"}'>
    {% for file in path.files %}
    <li class="{{file.IsSelected?'selected':(file.IsHighlighted?'highlighted':'')}} {{file.Icon}} {{file.IsManaged?'managedfile':''}}"><a href="{{file.Url}}" class="navigate" data-finder='{"name":"{{file.Name}}","path":"{{file.Path}}"}' title="{{file.IsManaged ? 'Managed - ' ~ attribute({'r': 'readonly', 'w':'writable'}, file.ManagedPerm) : ''}}">{{file.Name}}</a></li>
    {% endfor %}
  </ul>
  {% endfor %}
  {% if fileInfo %}
  <ul class="fileinfo">
    {% for Key,Value in fileInfo %}
    <li class="fileinfo-{{Key|lower}}">{{Value|raw}}</li>
    {% endfor %}
  </ul>
  {% endif %}
  </div>
  <div class="btn-toolbar">
    <div class="btn-group">
      {% for action in actions %}
      <a href="{{action.Action}}" class="btn {{action.Class}}" title="{{action.Tooltip ? action.Tooltip : ''}}" data-finder='{{action.Data ? action.Data|json_encode : ''}}'>{{action.Label}}</a>
      {% endfor %}
    </div>
    <select></select>
    <div class="btn-group">
      <button class="btn cancel">Cancel</button>
      <button class="btn btn-primary select" {{selection?'':'disabled=""'}}>Select</button>
    </div>
  </div>
</div>
{% endspaceless %}
TPL
);
        $vars = array();
        $selected = (array)$_GET['path'];
        if ($_GET['public'] == 'true') {
            $virtual = array();
            foreach ($selected as $s) {
                $virtual[] = self::publicToVirtual($s);
            }
            $selected = $virtual;
        }
        
        // Verify selection and show selection info
        if (count($selected)) {
            try {
                $vars['fileInfo'] = $this->getFileInfo($selected);
                $selection = array();
                foreach ($selected as $s) {
                    $physical = self::virtualToPhysical($s);
                    $public = self::virtualToPublic($s);
                    $selection[] = $public;
                    if (isset($_GET['type'])) {
                        if ($_GET['type'] == 'folder' && !is_dir($physical)) {
                            $selection = false;
                            break;
                        }
                        if ($_GET['type'] == 'file' && !is_file($physical)) {
                            $selection = false;
                            break;
                        }
                    }
                }
                if ($selection)
                	$vars['selection'] = join(PATH_SEPARATOR, $selection);
            } catch (Exception $e) {
                $selected = array();
            }
        }
        
        // Show actions
        if ($selected && $selected[0]) {
            $vars['actions'] = array(
                array(
                    'Label' => 'Download',
                    'Action' => (string)url('', array('module','view'=>'Download','path'=>$selected)),
                ),
            );
            if ($this->isPhysicalWritable(self::virtualToPhysical($selected[0]))) {
                $vars['actions'][] = array(
                    'Label' => 'Upload',
                    'Action' => (string)url('', array('module','view','path'=>$selected[0],'action'=>'Upload')),
                    'Class' => 'upload',
                );
                $vars['actions'][] = array(
                    'Label' => 'Expunge',
                    'Action' => (string)url('', array('module','view','path'=>$selected,'action'=>'Delete', 'method' => 'Expunge')),
                    'Class' => 'delete expunge',
                    'Tooltip' => 'Physically delete file/folder and mark Managedfile record(s) for deletion.',
                );
                $vars['actions'][] = array(
                    'Label' => 'Purge',
                    'Action' => (string)url('', array('module','view','path'=>$selected,'action'=>'Delete', 'method' => 'Purge')),
                    'Class' => 'delete purge',
                    'Tooltip' => 'Physically delete file/folder and Managedfile record(s).',
                );
                $vars['actions'][] = array(
                    'Label' => 'Create directory',
                    'Action' => (string)url('', array('module','view','path'=>$selected[0],'action'=>'CreateDirectory')),
                    'Class' => 'create-directory',
                );
                if (count($selected) == 1) {
                    $vars['actions'][] = array(
                        'Label' => 'Rename',
                        'Action' => (string)url('', array('module','view','path'=>$selected[0],'action'=>'Rename')),
                        'Class' => 'rename',
                        'Data' => array('name' => basename($selected[0])),
                    );
                }
            }
        }
        
        $vars['paths'] = self::getPaths($selected);
        $content = $template->render($vars);
        if (isAjax()) {
            $this->returnJson(array(
                'content' => $content,
                'maxUploadSize' => Curry_Util::computerReadableBytes(get_cfg_var('upload_max_filesize')),
                'path' => $selected,
            ));
        } else {
            return $content;
        }
        return '';
    }
    
    /**
     * Delete file.
     *
     * @param array $params
     * @return array
     */
    public function actionDelete($params)
    {
        $paths = (array)$params['path'];
        foreach ($paths as $path) {
            $path = self::virtualToPhysical($path);
            if (!file_exists($path)) {
                throw new Exception('The file to delete could not be found.');
            }
            
            // update Managedfile records.
            $error = '';
            if ($this->handleManagedfileDelete($path, $params, $error)) {
                self::trashFile($path);
            } else {
                return array('status' => 0, 'error' => $error);
            }
        }
        return array('status' => 1);
    }
    
    protected function handleManagedfileDelete($absPath, $params, &$error)
    {
        $relPath = self::absoluteToRelativePath($absPath);
        $q = ManagedfileQuery::create()
           ->filterByFilepath(is_dir($absPath) ? $relPath.DIRECTORY_SEPARATOR.'%' : $relPath);
        
        $qc = clone $q;
        foreach ($qc->find() as $mf) {
            if (!$mf->isWritable()) {
                $error = 'You do not have permission to delete entity: '.$mf->getFilepath();
                return false;
            }
        }
        
        if ($params['method'] == 'Expunge') {
            // do not physically delete a Managedfile record since many content items could be referencing it.
            // Expunge will mark the record as deleted event though the file/dir is physically deleted.
            $q->update(array('Deleted' => true));
        } elseif ($params['method'] == 'Purge') {
            // physically remove records from the Managedfile table.
            $q->delete();
        }
        
        return true;
    }
    
    /**
     * Move file.
     *
     * @param array $params
     * @return array
     */
    public function actionMove($params)
    {
        $overwrite = $params['overwrite'];
        $destinationPath = self::virtualToPhysical($params['destination']);
        if (!file_exists($destinationPath)) {
            throw new Exception('Destination path could not be found.');
        }
        
        $conflicted = array();
        $move = array();
        $paths = (array)$params['path'];
        foreach ($paths as $path) {
            $path = self::virtualToPhysical($path);
            $destination = $destinationPath . DIRECTORY_SEPARATOR . basename($path);
            if (is_dir($path) && strpos($destinationPath.'/', $path.'/') === 0) {
                throw new Exception('Invalid operation: Unable to move folder inside self.');
            }
            
            if (file_exists($destination)) {
                if (!$overwrite) {
                    $conflicted[] = basename($path);
                    continue;
                }
                self::trashFile($destination);
            }
            $move[$path] = $destination;
        }
        
        if (count($conflicted)) {
            return array('status' => 0, 'error' => 'File already exist: ' . join(', ', $conflicted), 'overwrite' => true);
        }
        
        foreach ($move as $source => $destination) {
            // update Managedfile records.
            $error = '';
            if ($this->handleManagedfileMove($source, $destination, $error)) {
                rename($source, $destination);
            } else {
                return array('status' => 0, 'error' => $error);
            }
        }
        
        return array('status' => 1);
    }
    
    protected function handleManagedfileMove($absSource, $absDestn, &$error)
    {
        $relSource = self::absoluteToRelativePath($absSource);
        $relDestn = self::absoluteToRelativePath($absDestn);
        
        $success = true;
        try {
            if (is_dir($absSource)) {
                // update files in this directory
                $relSource .= DIRECTORY_SEPARATOR;
                $relDestn .= DIRECTORY_SEPARATOR;
                 
                $rows = ManagedfileQuery::create()
                    ->filterByFilepath("$relSource%")
                    ->find();
                
                foreach ($rows as $mf) {
                    if (!$mf->isWritable()) {
                        $error = 'You do not have permission to move entity: '.$mf->getFilepath();
                        return false;
                    }
                }
                
                foreach ($rows as $row) {
                    try {
                       $row->setFilepath(str_replace($relSource, $relDestn, $row->getFilepath()))
                           ->save();
                    } catch (Exception $e) {
                        // ignore errors. Possible duplicate entry errors.
                        trace(__METHOD__ . ':' . $e->getMessage());
                    }
                }
            } else {
                $mf = ManagedfileQuery::create()
                    ->findOneByFilepath($relSource);
                
                if ($mf && $mf->isWritable()) {
                    ManagedfileQuery::create()
                       ->filterByFilepath($relSource)
                       ->update(array('Filepath' => $relDestn));
                } else {
                    $error = 'You do not have permission to move file: '.$mf->getFilepath();
                    return false;
                }
            }
        } catch (Exception $e) {
            // Possible duplicate entry error. Do not move file.
            $success = false;
            trace(__METHOD__ . ':' . $e->getMessage());
        }
        
        return $success;
    }
    
    /**
     * Rename file.
     *
     * @param array $params
     * @return array
     */
    public function actionRename($params)
    {
        $name = $params['name'];
        $path = self::virtualToPhysical($params['path']);
        if (!self::isPhysicalWritable($path)) {
            throw new Exception('Access denied');
        }
        $target = dirname($path).'/'.$name;
        
        if (!file_exists($path)) {
            throw new Exception('Source does not exist');
        }
        
        if (file_exists($target)) {
            throw new Exception('Destination already exists.');
        }
        
        // update Managedfile records.
        $error = '';
        if ($this->handleManagedfileRename($path, $target, $error)) {
            if (!rename($path, $target)) {
                throw new Exception('Unable to rename file: '.$path);
            }
        } else {
            return array('status' => 0, 'error' => $error);
        }
        
        return array('status' => 1);
    }
    
    protected function handleManagedfileRename($absOldPath, $absNewPath, &$error)
    {
        $relOldPath = self::absoluteToRelativePath($absOldPath);
        $relNewPath = self::absoluteToRelativePath($absNewPath);
        
        if (is_dir($absOldPath)) {
            $relOldPath .= DIRECTORY_SEPARATOR;
            $relNewPath .= DIRECTORY_SEPARATOR;
            
            $rows = ManagedfileQuery::create()
               ->filterByFilepath("$relOldPath%")
               ->find();
            
            // check write permissions.
            foreach ($rows as $mf) {
                if (!$mf->isWritable()) {
                    $error = 'You do not have permissions to rename entity: '.$mf->getFilepath();
                    return false;
                }
            }
            
            // update Managedfile table.
            foreach ($rows as $row) {
                $row->setFilepath(str_replace($relOldPath, $relNewPath, $row->getFilepath()))
                   ->save();
            }
        } else {
            // check write permission on this file.
            $mf = ManagedfileQuery::create()
                ->findOneByFilepath($relOldPath);
                
            if (!$mf->isWritable()) {
                $error = 'You do not have permissions to rename file: '.$relOldPath;
                return false;
            }
            
            // update Managedfile table.
            ManagedfileQuery::create()
               ->filterByFilepath($relOldPath)
               ->update(array('Filepath' => $relNewPath));
        }
        
        return true;
    }
    
    /**
     * Upload file.
     * @param array $params
     * @return array
     */
    public function actionUpload($params)
    {
        if (!isset($_FILES['file'])) {
            throw new Exception('No file to upload.');
        }
        
        $result = array(
            'status' => 1,
            'overwrite' => array(),
            'uploaded_virtual' => array(),
            'uploaded_public' => array(),
        );
        
        $virtualPath = $params['path'];
        if ($params['public'] == 'true') {
            $virtualPath = self::publicToVirtual($virtualPath);
        }
        
        $targetPath = self::virtualToPhysical($virtualPath);
        if (!self::isPhysicalWritable($targetPath)) {
            throw new Exception('Access denied');
        }
        
        if (is_file($targetPath)) {
            $virtualPath = dirname($virtualPath);
            $targetPath = dirname($targetPath);
        } elseif (is_dir($targetPath)) {
            $relPath = self::absoluteToRelativePath($targetPath) . '/';
            $o = ManagedfileQuery::create()->findOneByFilepath($relPath);
            if ($o && !$o->isWritable()) {
                return array('status' => 0, 'error' => 'You do not have write permissions on the directory: '.$relPath);
            }
        }
        
        $overwrite = array();
        foreach ((array)$_FILES['file']['error'] as $key => $error) {
            if ($error) {
                throw new Exception('Upload error: '.Curry_Util::uploadCodeToMessage($error));
            }
            
            $name = self::filterFilename($_FILES['file']['name'][$key]);
            $source = $_FILES['file']['tmp_name'][$key];
            $target = $targetPath . '/' . $name;
            if (file_exists($target)) {
                $targetHash = sha1_file($target);
                $sourceHash = sha1_file($source);
                if ($targetHash !== $sourceHash) {
                    $result['overwrite'][] = $name;
                    $result['status'] = 0;
                    $overwrite[$name] = array(
                        'target' => $target,
                        'temp' => $sourceHash,
                    );
                    $target = Curry_Core::$config->curry->tempPath . DIRECTORY_SEPARATOR . $sourceHash;
                    move_uploaded_file($source, $target);
                    continue;
                }
            } else {
                move_uploaded_file($source, $target);
            }
            
            // check whether uploaded file matches a record whose Deleted status is TRUE and change status.
            ManagedfileQuery::create()
                ->filterByFilepath(self::absoluteToRelativePath($target))
                ->update(array('Deleted' => false));
            
            $result['uploaded_virtual'][] = $virtualPath . '/' . $name;
            $result['uploaded_public'][] = self::physicalToPublic($target);
        }
        $ses = new Zend_Session_Namespace(__CLASS__);
        $ses->uploadOverwrite = $overwrite;
        return $result;
    }
    
    public function getPaths($selected)
    {
        $paths = parent::getPaths($selected);
        $managedfileCache = $this->getManagedfilesFromCache();
        foreach ($paths as &$path) {
            foreach ($path['files'] as $delta => $file) {
                $vp = $file['Path']; // this is a virtual path
                $pp = self::virtualToPublic($vp);
                if ($file['IsFolder']) {
                    $pp .= DIRECTORY_SEPARATOR;
                }
                
                // check whether this entity is managed and show based on read permission.
                $mf = $managedfileCache[$pp];
                $path['files'][$delta]['IsManaged'] = (null !== $mf);
                if ($mf) {
                    if ($mf->isReadable()) {
                        $path['files'][$delta]['ManagedPerm'] = ($mf->isWritable() ? 'w' : 'r');
                    } else {
                        // do not show this entity if logged in user does not have read permission.
                        unset($path['files'][$delta]);
                    }
                }
                //trace($path['files'][$delta]); //MARK
            }
        }
        return $paths;
    }
    
    protected function getManagedfilesFromCache($ttl = 1800)
    {
        $cacheName = __CLASS__.'_'.md5(__METHOD__);
        try {
            if ( ($ret = Curry_Core::$cache->load($cacheName)) === false) {
                trace('Rebuilding Managedfiles cache.');
                $colManagedfile = ManagedfileQuery::create()->find();
                $ret = Curry_Array::objectsToArray($colManagedfile, 'getFilepath');
                Curry_Core::$cache->save($ret, $cacheName, array(), $ttl);
            }
        } catch (Exception $e) {
            throw $e;
        }
        
        return $ret;
    }
    
}
