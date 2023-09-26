<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Authorization\Model\Acl\Loader;

use Magento\Authorization\Model\Acl\Role\Group as RoleGroup;
use Magento\Authorization\Model\Acl\Role\User as RoleUser;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Serialize\Serializer\Json;

class Role implements \Magento\Framework\Acl\LoaderInterface
{
    /**
     * Cache key for ACL roles cache
     */
    const ACL_ROLES_CACHE_KEY = 'authorization_role_cached_data';

    /**
     * @var \Magento\Framework\App\ResourceConnection
     */
    protected $_resource;

    /**
     * @var \Magento\Authorization\Model\Acl\Role\GroupFactory
     */
    protected $_groupFactory;

    /**
     * @var \Magento\Authorization\Model\Acl\Role\UserFactory
     */
    protected $_roleFactory;

    /**
     * @var \Magento\Framework\Acl\Data\CacheInterface
     */
    private $aclDataCache;

    /**
     * @var Json
     */
    private $serializer;

    /**
     * @var string
     */
    private $cacheKey;

    /**
     * @param \Magento\Authorization\Model\Acl\Role\GroupFactory $groupFactory
     * @param \Magento\Authorization\Model\Acl\Role\UserFactory $roleFactory
     * @param \Magento\Framework\App\ResourceConnection $resource
     * @param \Magento\Framework\Acl\Data\CacheInterface $aclDataCache
     * @param Json $serializer
     * @param string $cacheKey
     */
    public function __construct(
        \Magento\Authorization\Model\Acl\Role\GroupFactory $groupFactory,
        \Magento\Authorization\Model\Acl\Role\UserFactory $roleFactory,
        \Magento\Framework\App\ResourceConnection $resource,
        \Magento\Framework\Acl\Data\CacheInterface $aclDataCache = null,
        Json $serializer = null,
        $cacheKey = self::ACL_ROLES_CACHE_KEY
    ) {
        $this->_resource = $resource;
        $this->_groupFactory = $groupFactory;
        $this->_roleFactory = $roleFactory;
        $this->aclDataCache = $aclDataCache ?: ObjectManager::getInstance()->get(
            \Magento\Framework\Acl\Data\CacheInterface::class
        );
        $this->serializer = $serializer ?: ObjectManager::getInstance()->get(Json::class);
        $this->cacheKey = $cacheKey;
    }

    /**
     * Populate ACL with roles from external storage
     *
     * @param \Magento\Framework\Acl $acl
     * @return void
     */
    public function populateAcl(\Magento\Framework\Acl $acl)
    {
        foreach ($this->getRolesArray() as $role) {
            $parent = $role['parent_id'] > 0 ? $role['parent_id'] : null;
            switch ($role['role_type']) {
                case RoleGroup::ROLE_TYPE:
                    $acl->addRole($this->_groupFactory->create(['roleId' => $role['role_id']]), $parent);
                    break;

                case RoleUser::ROLE_TYPE:
                    if (!$acl->hasRole($role['role_id'])) {
                        $acl->addRole($this->_roleFactory->create(['roleId' => $role['role_id']]), $parent);
                    } else {
                        $acl->addRoleParent($role['role_id'], $parent);
                    }
                    break;
            }
        }
    }

    /**
     * Get application ACL roles array
     *
     * @return array
     */
    private function getRolesArray()
    {
        $rolesCachedData = $this->aclDataCache->load($this->cacheKey);
        if ($rolesCachedData) {
            return $this->serializer->unserialize($rolesCachedData);
        }

        $roleTableName = $this->_resource->getTableName('authorization_role');
        $connection = $this->_resource->getConnection();

        $select = $connection->select()
            ->from($roleTableName)
            ->order('tree_level');

        $rolesArray = $connection->fetchAll($select);
        $this->aclDataCache->save($this->serializer->serialize($rolesArray), $this->cacheKey);
        return $rolesArray;
    }
}
