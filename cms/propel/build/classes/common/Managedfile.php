<?php
/**
 * Subclass for representing a row from the 'managedfile' table.
 *
 * @package    propel.generator.common
 * @subpackage  Managedfile
 * @category    Curry CMS
 * @author      Jose F. D'Silva
 */
class Managedfile extends BaseManagedfile
{
    const PERM_READ_MASK = 0x02;
    const PERM_WRITE_MASK = 0x01;
    const ENTITY_TYPE_FILE = 'f';
    const ENTITY_TYPE_DIR = 'd';
    // undefined entity type
    const ENTITY_TYPE_UND = 'u';
    
    
    /**
     * Create a new record in the Managedfile table or return an existing one.
     * If $verifypath == TRUE, a new record will be created only if the file exists.
     *
     * @param string $path      Relative path offset from the www/ folder
     * @param boolean $verifypath
     * @return NULL|Managedfile
     */
    public static function createManagedfileRow($path, $verifypath = false)
    {
        $create = true;
        if ($verifypath) {
            $fullpath = Curry_Core::$config->curry->wwwPath . DIRECTORY_SEPARATOR . $path;
            $create = file_exists($fullpath);
        }
        
        $managedfile = null;
        if ($create) {
            $entityType = self::getEntityType($path);
            if ($entityType === self::ENTITY_TYPE_DIR) {
                $path .= DIRECTORY_SEPARATOR;
            }
            
            // check whether the record exists in the Managedfile table.
            $managedfile = ManagedfileQuery::create()->findOneByFilepath($path);
            if ($managedfile) {
                return $managedfile;
            }
            
            $mime = self::getMimeType($path);
            $managedfile = new Managedfile();
            $managedfile->setFilepath($path)
                ->setType($entityType)
                ->setFilemime($mime)
                ->setOwner(User::getUser())
                ->save();
        }
        
        return $managedfile;
    }
    
    public static function getEntityType($path)
    {
        $ret = self::ENTITY_TYPE_UND;
        $fullpath = Curry_Core::$config->curry->wwwPath . DIRECTORY_SEPARATOR . $path;
        if (is_dir($fullpath)) {
            $ret = self::ENTITY_TYPE_DIR;
        } elseif (is_file($fullpath)) {
            $ret = self::ENTITY_TYPE_FILE;
        }
        
        return $ret;
    }
    
    public static function getMimeType($path)
    {
        $fullpath = Curry_Core::$config->curry->wwwPath . DIRECTORY_SEPARATOR . $path;
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        return $finfo->file($fullpath);
    }
    
    /**
     * Return the Managedfile fileId from the Managedfile table.
     * @param string $path         Public path to the file
     * @param boolean $create      Whether to create a Managedfile record if the path isn't found in the Managedfile table.
     * @param boolean $verifypath  Whether to create a new record if $path exists on the filesystem and is not found in the Managed table.
     * @return NULL|integer
     */
    public static function getManagedFid($path, $create = false, $verifypath = false)
    {
        $fid = null;
        $managedfile = ManagedfileQuery::create()->findOneByFilepath($path);
        
        if ((null === $managedfile) && $create) {
            $managedfile = self::createManagedfileRow($path, $verifypath);
        }
        
        if ($managedfile) {
            $fid = $managedfile->getPrimaryKey();
        }
        
        return $fid;
    }
    
    /**
     * Return the Filepath for the $fid.
     * If $verifypath is TRUE, the method will check whether the path exists on the filesystem and return an empty string if path is not found.
     *
     * @param int $fid
     * @param boolean $verifypath   Verify whether path exists.
     * @return string
     */
    public static function getManagedFilepath($fid, $verifypath = false)
    {
        $managedfile = ManagedfileQuery::create()->findPk($fid);
        $path = $managedfile ? $managedfile->getFilepath() : '';
        if ($verifypath && ('' !== $path)) {
            // Verify whether file exists.
            $fullpath = Curry_Core::$config->curry->wwwPath . DIRECTORY_SEPARATOR . $path;
            if (! file_exists($fullpath)) {
                $path = '';
            }
        }
        
        return $path;
    }
    
    /**
     * Return read permission on a group.
     * @param string $group    Group (owner|role|world) on which read permission should be checked for.
     * @return boolean
     */
    public function getReadPerm($group)
    {
        $word = $this->getPermission();
        return self::hasReadPerm(self::getGroupByte($word, $group));
    }
    
    /**
     * Return write permission on a group.
     * @param string $group    Group (owner|role|world) on which read permission should be checked for.
     * @return boolean
     */
    public function getWritePerm($group)
    {
        $word = $this->getPermission();
        return self::hasWritePerm(self::getGroupByte($word, $group));
    }
    
    /**
     * Return the byte for the specified group.
     * @param string $word      Permission word (rwrwrw)
     * @param string $group     (owner|role|world)
     * @throws Exception
     * @return int
     */
    public static function getGroupByte($word, $group)
    {
        if ($group == 'owner') {
            $byte = substr((string)$word, 0, 1);
        } elseif ($group == 'role') {
            $byte = substr((string)$word, 1, 1);
        } elseif ($group == 'world') {
            $byte = substr((string)$word, 2, 1);
        } else {
            throw new Exception('Invalid permission group.');
        }
        return (int)$byte;
    }
    
    /**
     * Helper function to construct and return a permission byte.
     * @param array $perm
     * @return int
     */
    public static function getPermissionByte(array $perm)
    {
        $byte = 0;
        if ($perm['read']) {
            $byte |= self::PERM_READ_MASK;
        }
        if ($perm['write']) {
            $byte |= self::PERM_WRITE_MASK;
        }
        return $byte;
    }
    
    /**
     * Helper function to construct and return the permission word.
     * @param array $owner    Owner permission byte
     * @param array $role     Role permission byte
     * @param array $world    World permission byte
     * @return string
     */
    public static function getPermissionWord(array $owner, array $role, array $world)
    {
        $oByte = self::getPermissionByte($owner);
        $rByte = self::getPermissionByte($role);
        $wByte = self::getPermissionByte($world);
        return "{$oByte}{$rByte}{$wByte}";
    }
    
    protected static function hasReadPerm($byte)
    {
        return (boolean) (($byte & self::PERM_READ_MASK) === self::PERM_READ_MASK);
    }
    
    protected static function hasWritePerm($byte)
    {
        return (boolean) (($byte & self::PERM_WRITE_MASK) === self::PERM_WRITE_MASK);
    }
    
    /**
     * Whether logged in user has read permission on this file.
     * Read operations include: visibility
     * @return boolean
     */
    public function isReadable()
    {
        $user = User::getUser();
        // check whether the logged in user owns this file.
        if ($user->getPrimaryKey() === $this->getOwnerId()) {
            return $this->getReadPerm('owner');
        }
        
        // check whether the logged in user belongs to the managedfile owner's role.
        if ($user->getUserRoleId() === $this->getOwner()->getUserRoleId()) {
            return $this->getReadPerm('role');
        }
        
        // check whether world read permission is set.
        return $this->getReadPerm('world');
    }
    
    /**
     * Whether a logged in user has write permission on this file.
     * Write operations include: rename, move, delete
     * @return boolean
     */
    public function isWritable()
    {
        $user = User::getUser();
        // check whether the logged in user owns this file.
        if ($user->getPrimaryKey() === $this->getOwnerId()) {
            return $this->getWritePerm('owner');
        }
        
        // check whether the logged in user belongs to the managedfile owner's role.
        if ($user->getUserRoleId() === $this->getOwner()->getUserRoleId()) {
            return $this->getWritePerm('role');
        }
        
        // check whether world write permission is set.
        return $this->getWritePerm('world');
    }
    
    public function setOwnerPermission(array $perm)
    {
        $word = $this->getPermission();
        $oByte = self::getPermissionByte($perm);
        $rByte = self::getGroupByte($word, 'role');
        $wByte = self::getGroupByte($word, 'world');
        $this->setPermission("{$oByte}{$rByte}{$wByte}");
        return $this;
    }
    
    public function setRolePermission(array $perm)
    {
        $word = $this->getPermission();
        $oByte = self::getGroupByte($word, 'owner');
        $rByte = self::getPermissionByte($perm);
        $wByte = self::getGroupByte($word, 'world');
        $this->setPermission("{$oByte}{$rByte}{$wByte}");
        return $this;
    }
    
    public function setWorldPermission(array $perm)
    {
        $word = $this->getPermission();
        $oByte = self::getGroupByte($word, 'owner');
        $rByte = self::getGroupByte($word, 'role');
        $wByte = self::getPermissionByte($perm);
        $this->setPermission("{$oByte}{$rByte}{$wByte}");
        return $this;
    }
    
    public function getHumanReadablePermissions($group = null)
    {
        $o = ($this->getReadPerm('owner') ? 'r' : '-') . ($this->getWritePerm('owner') ? 'w' : '-');
        $r = ($this->getReadPerm('role') ? 'r' : '-') . ($this->getWritePerm('role') ? 'w' : '-');
        $w = ($this->getReadPerm('world') ? 'r' : '-') . ($this->getWritePerm('world') ? 'w' : '-');
        
        if ($group == 'owner') {
            return $o;
        } elseif ($group == 'role') {
            return $r;
        } elseif ($group == 'world') {
            return $w;
        }
        
        return "$o $r $w";
    }
}
