<?php
class Common_Backend_Managedfile_Tools extends Curry_Backend
{
    public static function getName()
    {
        return 'Managedfile tools';
    }
    
    protected function renderMenu()
    {
        $this->addMenuItem('List', url('', array('module', 'view' => 'Main')));
        $this->addMenuItem('Add', url('', array('module', 'view' => 'MainAdd')), 'Update Managedfile table with data from content.');
        $this->addMenuItem('Update', url('', array('module', 'view' => 'MainUpdate')), 'Update content with data from Managedfile table.');
        $this->addMenuItem('Purge', url('', array('module', 'view' => 'MainPurge')), 'Delete all Managedfile records marked for deletion.');
    }
    
    public function showMain()
    {
        $user = User::getUser();
        $role = $user->getUserRole();
        if ($role->getPrimaryKey() != 1 && $role->getName() != 'Super') {
            throw new Exception('This module can be used by an administrator only.');
        }
        
        $this->renderMenu();
        $mfOwner = new Curry_Form_ModelForm('Managedfile', array(
                'columnElements' => array(
                        'filepath' => false,
                        'type' => false,
                        'filemime' => false,
                        'deleted' => false,
                        'permission' => false,
                        'created_at' => false,
                        'updated_at' => false,
                        'relation__owner' => array('select', array(
                                'label' => 'File owner',
                                'multiOptions' => UserQuery::create()->find()->toKeyValue('UserId', 'Name'),
                                'required' => true,
                        )),
                ),
                'withRelations' => array('Owner'),
        ));
        
        // create the list view
        $q = ManagedfileQuery::create();
        $list = new Curry_ModelView_List($q, array(
                'title' => 'Managedfiles',
                'modelForm' => array($this, 'showMainNewEntity'),
                'hide' => array('type'),
                'columns' => array(
                        'filepath' => array('action' => false),
                        'owner' => array(
                                'order' => 3,
                                'label' => 'Owner',
                                'callback' => function($o) {
                                    return (string)$o->getOwner();
                                },
                                'action' => 'set_owner',
                        ),
                        // show human readable permissions
                        'permission' => array(
                                'order' => 4,
                                'callback' => function($o) {
                                    return $o->getHumanReadablePermissions();
                                },
                                'action' => 'set_permissions',
                        ),
                        'deleted' => array(
                                'order' => 5,
                                'label' => 'Expunged',
                        ),
                ),
                'actions' => array(
                        'set_permissions' => array(
                                'label' => 'Change file permissions',
                                'href' => (string)url('', array('module', 'view' => 'MainFilePerm')),
                                'single' => true,
                                'class' => 'inline',
                        ),
                        'set_owner' => array(
                                'label' => 'Change file owner',
                                'action' => new Curry_ModelView_Form($mfOwner),
                                'single' => true,
                                'class' => 'inline',
                        ),
                ),
        ));
        
        $list->removeAction('edit');
        $list->removeAction('delete');
        // render the list
        $list->show($this);
    }
    
    /**
     * Handle processing for a new entity.
     */
    public function showMainNewEntity()
    {
        $form = $this->getNewEntityForm();
        if (isPost() && $form->isValid($_POST)) {
            $values = $form->getValues(true);
            $this->returnPartial($this->saveNewEntity($values));
        }
        
        $this->returnPartial($form);
    }
    
    protected function getNewEntityForm()
    {
        $form = new Curry_Form(array(
            'action' => url('', $_GET),
            'method' => 'post',
            'elements' => array(
                'path' => array('filebrowser', array(
                    'label' => 'File/Folder path',
                    'required' => true,
                    'description' => 'If you want to specify a folder, type the public path without a trailing slash (e.g. images/site).',
                    'filebrowserOptions' => array(
                        'local' => false,
                    ),
                )),
                'owner_id' => array('select', array(
                    'label' => 'Owner',
                    'multiOptions' => UserQuery::create()->find()->toKeyValue('UserId', 'Name'),
                    'value' => User::getUser()->getUserRoleId(),
                    'required' => true,
                )),
                'oread' => array('checkbox', array(
                    'label' => 'Read',
                    'value' => true,
                )),
                'owrite' => array('checkbox', array(
                    'label' => 'Write',
                    'value' => true,
                )),
                'rread' => array('checkbox', array(
                    'label' => 'Read',
                    'value' => true,
                )),
                'rwrite' => array('checkbox', array(
                    'label' => 'Write',
                    'value' => true,
                )),
                'wread' => array('checkbox', array(
                    'label' => 'Read',
                    'value' => true,
                )),
                'wwrite' => array('checkbox', array(
                    'label' => 'Write',
                    'value' => true,
                )),
            ),
        ));
        $form->addDisplayGroup(array('oread', 'owrite'), 'grpOwner', array('legend' => 'Owner permissions', 'class' => 'advanced'));
        $form->addDisplayGroup(array('rread', 'rwrite'), 'grpRole', array('legend' => 'Role permissions', 'class' => 'advanced'));
        $form->addDisplayGroup(array('wread', 'wwrite'), 'grpWorld', array('legend' => 'World permissions', 'class' => 'advanced'));
        $form->addElement('submit', 'save', array('label' => 'Save'));
        
        return $form;
    }
    
    protected function saveNewEntity(array $values)
    {
        $con = Propel::getConnection(ManagedfilePeer::DATABASE_NAME);
        $con->beginTransaction();
        try {
            $managedfile = Managedfile::createManagedfileRow($values['path']);
            $managedfile->setOwnerId($values['owner_id'])
            ->setPermission(Managedfile::getPermissionWord(array(
                'read' => $values['oread'],
                'write' => $values['owrite']
            ), array(
                'read' => $values['rread'],
                'write' => $values['rwrite']
            ), array(
                'read' => $values['wread'],
                'write' => $values['wwrite']
            )))
            ->save($con);
        
            $con->commit();
            $this->createModelUpdateEvent('Managedfile', $managedfile->getPrimaryKey(), 'new');
        } catch (Exception $e) {
            $con->rollback();
            return $e->getMessage();
        }
        
        return '';
    }
    
    public function showMainFilePerm()
    {
        // the primary key of the row that was clicked is available in the 'item' url parameter.
        $pk = $_GET['item'];
        $mf = ManagedfileQuery::create()->findPk($pk);
        $form = new Curry_Form(array(
                'action' => url('', $_GET),
                'method' => 'post',
                'elements' => array(
                        'oread' => array('checkbox', array(
                                'label' => 'Read',
                                'value' => $mf->getReadPerm('owner'),
                        )),
                        'owrite' => array('checkbox', array(
                                'label' => 'Write',
                                'value' => $mf->getWritePerm('owner'),
                        )),
                        'rread' => array('checkbox', array(
                                'label' => 'Read',
                                'value' => $mf->getReadPerm('role'),
                        )),
                        'rwrite' => array('checkbox', array(
                                'label' => 'Write',
                                'value' => $mf->getWritePerm('role'),
                        )),
                        'wread' => array('checkbox', array(
                                'label' => 'Read',
                                'value' => $mf->getReadPerm('world'),
                        )),
                        'wwrite' => array('checkbox', array(
                                'label' => 'Write',
                                'value' => $mf->getWritePerm('world'),
                        )),
                ),
        ));
        $form->addDisplayGroup(array('oread', 'owrite'), 'grpOwner', array('legend' => 'Owner permissions'));
        $form->addDisplayGroup(array('rread', 'rwrite'), 'grpRole', array('legend' => 'Role permissions'));
        $form->addDisplayGroup(array('wread', 'wwrite'), 'grpWorld', array('legend' => 'World permissions'));
        $form->addElement('submit', 'save', array('label' => 'Update permisisons'));
        
        if (isPost() && $form->isValid($_POST)) {
            $values = $form->getValues();
            $operm = array('read' => (boolean)$values['oread'], 'write' => (boolean)$values['owrite']);
            $rperm = array('read' => (boolean)$values['rread'], 'write' => (boolean)$values['rwrite']);
            $wperm = array('read' => (boolean)$values['wread'], 'write' => (boolean)$values['wwrite']);
            $mf->setPermission(Managedfile::getPermissionWord($operm, $rperm, $wperm))
                ->save();
            $this->createModelUpdateEvent('Managedfile', $mf->getPrimaryKey(), 'update');
            return '';
        }
        
        $this->addMainContent($form);
    }
    
    public function showMainAdd()
    {
        $this->renderMenu();
        $form = $this->getAddForm();
        if (isPost() && $form->isValid($_POST)) {
            $this->handleAdd($form->getValues());
            return;
        }
        
        $this->addMainContent($this->getHelp($_GET['view']));
        $this->addMainContent($form);
    }
    
    protected function handleAdd(array $values)
    {
        $tableName = $values['table_name'];
        $colName = $values['column_name'];
        $tableMap = PropelQuery::from($tableName)->getTableMap();
        $colMap = $tableMap->getColumn($colName);
        $fidColMap = $tableMap->getColumn("{$colName}_fid");
        
        $rows = PropelQuery::from($tableName)
            ->where("{$tableMap->getPhpName()}.{$colMap->getPhpName()} IS NOT NULL")
            ->_or()
            ->filterBy($colMap->getPhpName(), '', Criteria::NOT_EQUAL)
            ->find();
        
        $nbCreated = 0;
        foreach ($rows as $row) {
            $filePath = $row->{'get'.$colMap->getPhpName()}();
            $managedfile = ManagedfileQuery::create()
                ->filterByFilepath($filePath)
                ->findOneOrCreate();
            if ($managedfile->isNew()) {
                $fullpath = Curry_Core::$config->curry->wwwPath . DIRECTORY_SEPARATOR . $filePath;
                $deleted = !file_exists($fullpath);
                $managedfile->setFilemime(Managedfile::getMimeType($filePath))
                    ->setDeleted($deleted)
                    ->save();
                ++ $nbCreated;
            }
            
            // update fid column
            $currentFid = $row->{'get'.$fidColMap->getPhpName()}();
            $realFid = $managedfile->getFid();
            if ($currentFid != $realFid) {
                $row->{'set'.$fidColMap->getPhpName()}($realFid)
                    ->save();
            }
        }
        
        $this->addMessage("Created {$nbCreated} new records in the Managedfile table.");
    }
    
    protected function getAddForm()
    {
        return new Curry_Form(array(
            'action' => url('', $_GET),
            'method' => 'post',
            'elements' => array(
                'table_name' => array('text', array(
                    'label' => 'Table PhpName',
                    'placeholder' => 'Enter Table PhpName',
                    'required' => true,
                )),
                'column_name' => array('text', array(
                    'label' => 'Column name',
                    'placeholder' => 'Enter Column name that contains file paths.',
                    'required' => true,
                )),
                'save' => array('submit', array('label' => 'Submit')),
            ),
        ));
    }
    
    public function showMainUpdate()
    {
        $this->renderMenu();
        $form = $this->getAddForm();
        if (isPost() && $form->isValid($_POST)) {
            $this->handleUpdate($form->getValues());
            return;
        }
        
        $this->addMainContent($this->getHelp($_GET['view']));
        $this->addMainContent($form);
    }
    
    protected function handleUpdate(array $values)
    {
        $tableName = $values['table_name'];
        $colName = $values['column_name'];
        $tableMap = PropelQuery::from($tableName)->getTableMap();
        $colMap = $tableMap->getColumn($colName);
        $fidColMap = $tableMap->getColumn("{$colName}_fid");
        
        $rows = PropelQuery::from($tableName)
            ->where("{$tableMap->getPhpName()}.{$colMap->getPhpName()} IS NOT NULL")
            ->_or()
            ->filterBy($colMap->getPhpName(), '', Criteria::NOT_EQUAL)
            ->find();
        
        $nbUpdated = 0;
        foreach ($rows as $row) {
            $managedfile = ManagedfileQuery::create()->findPk($row->{'get'.$fidColMap->getPhpName()}());
            if ($managedfile) {
                $oldFilepath = $row->{'get'.$colMap->getPhpName()}();
                $newFilepath = $managedfile->getFilepath();
                if ($oldFilepath != $newFilepath) {
                    $row->{'set'.$colMap->getPhpName()}($newFilepath)
                        ->save();
                    ++ $nbUpdated;
                }
            }
        }
        
        $this->addMessage("Updated file paths for $nbUpdated rows.");
    }
    
    public function showMainPurge()
    {
        $this->renderMenu();
        
        $form = $this->getPurgeForm();
        if (isPost() && $form->isValid($_POST)) {
            if ($form->refresh->isChecked()) {
                $this->refreshDeletedStatus();
            } elseif ($form->purge->isChecked()) {
                $this->purge();
                return;
            }
        }
        
        $this->addMainContent($this->getHelp($_GET['view']));
        $this->addMainContent($form);
    }
    
    protected function getPurgeForm()
    {
        return new Curry_Form(array(
            'action' => url('', $_GET),
            'method' => 'post',
            'elements' => array(
                'refresh' => array('submit', array('label' => 'Refresh Deleted status', 'style' => 'float:left')),
                'purge' => array('submit', array('label' => 'Purge records marked for deletion', 'style' => 'float:left')),
            ),
        ));
    }
    
    /**
     * Cross-check every Managedfile record and verify that file exists or is deleted and update the deleted status.
     */
    protected function refreshDeletedStatus()
    {
        $nbUpdated = 0;
        $rows = ManagedfileQuery::create()->find();
        foreach ($rows as $row) {
            $fullPath = Curry_Core::$config->curry->wwwPath . DIRECTORY_SEPARATOR . $row->getFilepath();
            $realStatus = !file_exists($fullPath);
            $currentStatus = $row->getDeleted();
            if ($currentStatus != $realStatus) {
                $row->setDeleted($realStatus)
                    ->save();
                ++ $nbUpdated;
            }
        }
        
        $this->addMessage("Updated $nbUpdated Managedfile records.");
    }
    
    protected function purge()
    {
        $nbDeleted = ManagedfileQuery::create()
            ->filterByDeleted(true)
            ->delete();
        
        $this->addMessage("$nbDeleted Managedfile records purged.");
    }
    
    protected function getHelp($view)
    {
        switch ($view) {
            case 'MainAdd':
                $dbRebuildUrl = (string) url('', array('module' => 'Curry_Backend_Database', 'view' => 'Propel'));
                $help =<<<HTML
<p><strong>Add file paths stored in content to the Managedfile table.</strong></p>

<pre>
Before you execute this function, you should modify your schema such that
the table from which file-path is to be extracted has an additional column suffixed by '_fid'.
When a path is stored into the Managedfile table, the fid column will be updated.

For example, let's say you have a table named "picture" having a column named "source" of type VARCHAR.
You should also add an additional column of type INTEGER named "source_fid"
<column name="source_fid" type="INTEGER" />
and <a href="$dbRebuildUrl">rebuild your database</a>.
</pre>
HTML;

                break;
            case 'MainUpdate':
                $help =<<<HTML
<p><strong>Update content file paths from the Managedfile table.</strong></p>

<pre>
This function is the reverse of Add.
It will update the column having file paths with the corresponding entry from the Managedfile table.
</pre>
HTML;

                break;
            case 'MainPurge':
                $help =<<<HTML
<pre>
With this tool you can purge all expunged Managedfile records.
When a file is physically deleted from the Managedfile browser, the corresponding managedfile record is not physically erased.
Only the deleted status is set. Such a record is known as 'Expunged'.
You can unmark an expunged record by uploading a file having the same name as the deleted one in the same path.
Purging implies permanently erasing such expunged records.
</pre>
HTML;

                break;
            default:
                $help = '';
                break;
        }
        
        return $help;
    }
    
}