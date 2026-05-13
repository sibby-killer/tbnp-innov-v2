<?php
/**
 * Authorization Manager - Handles role-based access control and permissions
 * Uses your ROLE_ADMIN, ROLE_DRIVER, ROLE_CLIENT constants
 * 
 * @package Core\Security
 * @version 1.0.0
 */

namespace Core\Security;

use Core\Security\Exceptions\SecurityException;

class Authorization
{
    /**
     * Role hierarchy levels
     */
    const ROLE_HIERARCHY = [
        'super_admin' => 100,
        'admin' => 90,
        'dispatcher' => 70,
        'fleet_manager' => 60,
        'driver' => 40,
        'client' => 20,
        'guest' => 0
    ];
    
    /**
     * @var SecurityLogger Logger instance
     */
    private SecurityLogger $logger;
    
    /**
     * @var \PDO Database connection
     */
    private \PDO $db;
    
    /**
     * @var array Cached permissions
     */
    private array $permissionCache = [];
    
    /**
     * @var array Cached roles
     */
    private array $roleCache = [];
    
    /**
     * @var array Configuration
     */
    private array $config;
    
    /**
     * Constructor
     * 
     * @param SecurityLogger $logger
     * @param \PDO $db
     * @param array $config
     */
    public function __construct(SecurityLogger $logger, \PDO $db, array $config = [])
    {
        $this->logger = $logger;
        $this->db = $db;
        
        $this->config = array_merge([
            'cache_permissions' => true,
            'cache_ttl' => 300, // 5 minutes
            'strict_mode' => true,
            'default_deny' => true
        ], $config);
    }
    
    /**
     * Check if user has permission
     * 
     * @param int $userId User ID
     * @param string $permission Permission name or identifier
     * @param mixed $resource Optional resource ID for ownership checks
     * @return bool
     */
    public function hasPermission(int $userId, string $permission, $resource = null): bool
    {
        try {
            // Get user's role
            $role = $this->getUserRole($userId);
            
            if (!$role) {
                $this->logger->logAccess('permission_check_failed', [
                    'user_id' => $userId,
                    'permission' => $permission,
                    'reason' => 'user_role_not_found'
                ], SecurityLogger::LEVEL_WARNING);
                
                return false;
            }
            
            // Check if user is super admin (has all permissions)
            if ($this->isSuperAdmin($role)) {
                return true;
            }
            
            // Get role permissions
            $permissions = $this->getRolePermissions($role['id']);
            
            // Check if permission exists in role's permissions
            if (!in_array($permission, $permissions)) {
                // Check parent roles in hierarchy
                if (!$this->checkRoleHierarchy($role['id'], $permission)) {
                    $this->logDeniedAccess($userId, $role, $permission, $resource);
                    return false;
                }
            }
            
            // Check resource-specific permissions if resource provided
            if ($resource !== null && !$this->checkResourceAccess($userId, $role, $permission, $resource)) {
                $this->logDeniedAccess($userId, $role, $permission, $resource, 'resource_restriction');
                return false;
            }
            
            // Log successful permission check
            $this->logger->logAccess('permission_granted', [
                'user_id' => $userId,
                'role_id' => $role['id'],
                'permission' => $permission,
                'resource' => $resource
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            $this->logger->logAccess('permission_check_error', [
                'user_id' => $userId,
                'permission' => $permission,
                'error' => $e->getMessage()
            ], SecurityLogger::LEVEL_ERROR);
            
            return !$this->config['strict_mode'];
        }
    }
    
    /**
     * Check if user has any of the given permissions
     * 
     * @param int $userId
     * @param array $permissions
     * @param mixed $resource
     * @return bool
     */
    public function hasAnyPermission(int $userId, array $permissions, $resource = null): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermission($userId, $permission, $resource)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if user has all of the given permissions
     * 
     * @param int $userId
     * @param array $permissions
     * @param mixed $resource
     * @return bool
     */
    public function hasAllPermissions(int $userId, array $permissions, $resource = null): bool
    {
        foreach ($permissions as $permission) {
            if (!$this->hasPermission($userId, $permission, $resource)) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Check if user has role
     * 
     * @param int $userId
     * @param int|string|array $roles Role ID, name, or array of roles
     * @return bool
     */
    public function hasRole(int $userId, $roles): bool
    {
        $userRole = $this->getUserRole($userId);
        
        if (!$userRole) {
            return false;
        }
        
        if (is_array($roles)) {
            foreach ($roles as $role) {
                if ($this->roleMatches($userRole, $role)) {
                    return true;
                }
            }
            return false;
        }
        
        return $this->roleMatches($userRole, $roles);
    }
    
    /**
     * Check if role matches given role identifier
     * 
     * @param array $userRole
     * @param int|string $role
     * @return bool
     */
    private function roleMatches(array $userRole, $role): bool
    {
        if (is_int($role)) {
            return $userRole['id'] == $role;
        }
        
        if (is_string($role)) {
            return strtolower($userRole['name']) === strtolower($role) ||
                   strtolower($userRole['slug']) === strtolower($role);
        }
        
        return false;
    }
    
    /**
     * Require permission or throw exception
     * 
     * @param int $userId
     * @param string $permission
     * @param mixed $resource
     * @throws SecurityException
     */
    public function requirePermission(int $userId, string $permission, $resource = null): void
    {
        if (!$this->hasPermission($userId, $permission, $resource)) {
            $role = $this->getUserRole($userId);
            
            throw SecurityException::unauthorized($permission, [
                'user_id' => $userId,
                'role_id' => $role['id'] ?? null,
                'role_name' => $role['name'] ?? null,
                'resource' => $resource
            ]);
        }
    }
    
    /**
     * Require role or throw exception
     * 
     * @param int $userId
     * @param int|string|array $roles
     * @throws SecurityException
     */
    public function requireRole(int $userId, $roles): void
    {
        if (!$this->hasRole($userId, $roles)) {
            $userRole = $this->getUserRole($userId);
            
            throw new SecurityException(
                "User does not have required role",
                [
                    'user_id' => $userId,
                    'user_role' => $userRole['name'] ?? 'unknown',
                    'required_roles' => $roles
                ],
                1002
            );
        }
    }
    
    /**
     * Get user's role
     * 
     * @param int $userId
     * @return array|null
     */
    public function getUserRole(int $userId): ?array
    {
        // Check cache
        if (isset($this->roleCache[$userId])) {
            return $this->roleCache[$userId];
        }
        
        // Get from database
        $stmt = $this->db->prepare("
            SELECT r.id, r.name, r.slug, r.level, r.is_super, r.description
            FROM roles r
            JOIN users u ON u.role_id = r.id
            WHERE u.id = ?
        ");
        $stmt->execute([$userId]);
        
        $role = $stmt->fetch() ?: null;
        
        // Cache if enabled
        if ($role && $this->config['cache_permissions']) {
            $this->roleCache[$userId] = $role;
        }
        
        return $role;
    }
    
    /**
     * Get role permissions
     * 
     * @param int $roleId
     * @return array
     */
    public function getRolePermissions(int $roleId): array
    {
        // Check cache
        if (isset($this->permissionCache[$roleId])) {
            return $this->permissionCache[$roleId];
        }
        
        // Get direct permissions
        $stmt = $this->db->prepare("
            SELECT p.name
            FROM permissions p
            JOIN role_permissions rp ON p.id = rp.permission_id
            WHERE rp.role_id = ?
        ");
        $stmt->execute([$roleId]);
        
        $permissions = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        
        // Get inherited permissions from parent roles
        $inherited = $this->getInheritedPermissions($roleId);
        $permissions = array_unique(array_merge($permissions, $inherited));
        
        // Cache if enabled
        if ($this->config['cache_permissions']) {
            $this->permissionCache[$roleId] = $permissions;
        }
        
        return $permissions;
    }
    
    /**
     * Get inherited permissions from role hierarchy
     * 
     * @param int $roleId
     * @return array
     */
    private function getInheritedPermissions(int $roleId): array
    {
        $stmt = $this->db->prepare("
            SELECT rp.permission_id, p.name
            FROM role_permissions rp
            JOIN permissions p ON rp.permission_id = p.id
            JOIN role_hierarchy rh ON rh.parent_role_id = rp.role_id
            WHERE rh.child_role_id = ?
        ");
        $stmt->execute([$roleId]);
        
        return $stmt->fetchAll(\PDO::FETCH_COLUMN, 1);
    }
    
    /**
     * Check role hierarchy for permission
     * 
     * @param int $roleId
     * @param string $permission
     * @return bool
     */
    private function checkRoleHierarchy(int $roleId, string $permission): bool
    {
        // Get all parent roles
        $stmt = $this->db->prepare("
            SELECT parent_role_id
            FROM role_hierarchy
            WHERE child_role_id = ?
        ");
        $stmt->execute([$roleId]);
        
        while ($parent = $stmt->fetch()) {
            $parentPermissions = $this->getRolePermissions($parent['parent_role_id']);
            if (in_array($permission, $parentPermissions)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if user is super admin
     * 
     * @param array $role
     * @return bool
     */
    private function isSuperAdmin(array $role): bool
    {
        return !empty($role['is_super']) || $role['level'] >= 100;
    }
    
    /**
     * Check resource-specific access
     * 
     * @param int $userId
     * @param array $role
     * @param string $permission
     * @param mixed $resource
     * @return bool
     */
    private function checkResourceAccess(int $userId, array $role, string $permission, $resource): bool
    {
        // Parse resource identifier
        if (is_string($resource) && strpos($resource, ':')) {
            list($resourceType, $resourceId) = explode(':', $resource, 2);
        } else {
            $resourceType = $this->guessResourceType($permission);
            $resourceId = $resource;
        }
        
        switch ($resourceType) {
            case 'order':
                return $this->checkOrderAccess($userId, $role, $permission, $resourceId);
                
            case 'truck':
                return $this->checkTruckAccess($userId, $role, $permission, $resourceId);
                
            case 'driver':
                return $this->checkDriverAccess($userId, $role, $permission, $resourceId);
                
            case 'client':
                return $this->checkClientAccess($userId, $role, $permission, $resourceId);
                
            default:
                // If resource type unknown, check if user owns the resource
                return $this->checkOwnership($userId, $resourceType, $resourceId);
        }
    }
    
    /**
     * Check order access
     * 
     * @param int $userId
     * @param array $role
     * @param string $permission
     * @param int $orderId
     * @return bool
     */
    private function checkOrderAccess(int $userId, array $role, string $permission, int $orderId): bool
    {
        // Admins and dispatchers can access all orders
        if (in_array($role['slug'], ['admin', 'super-admin', 'dispatcher'])) {
            return true;
        }
        
        $stmt = $this->db->prepare("
            SELECT client_id, driver_id, courier_company_id
            FROM orders
            WHERE id = ?
        ");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        
        if (!$order) {
            return false;
        }
        
        // Drivers can only access their assigned orders
        if ($role['slug'] === 'driver') {
            return $order['driver_id'] == $userId;
        }
        
        // Clients can only access their own orders
        if ($role['slug'] === 'client') {
            return $order['client_id'] == $userId;
        }
        
        // Fleet managers can access orders for their company
        if ($role['slug'] === 'fleet_manager') {
            $companyId = $this->getUserCompanyId($userId);
            return $order['courier_company_id'] == $companyId;
        }
        
        return false;
    }
    
    /**
     * Check truck access
     * 
     * @param int $userId
     * @param array $role
     * @param string $permission
     * @param int $truckId
     * @return bool
     */
    private function checkTruckAccess(int $userId, array $role, string $permission, int $truckId): bool
    {
        // Admins and fleet managers can access all trucks
        if (in_array($role['slug'], ['admin', 'super-admin', 'fleet_manager'])) {
            return true;
        }
        
        $stmt = $this->db->prepare("
            SELECT courier_company_id, assigned_driver_id
            FROM trucks
            WHERE id = ?
        ");
        $stmt->execute([$truckId]);
        $truck = $stmt->fetch();
        
        if (!$truck) {
            return false;
        }
        
        // Drivers can only access their assigned truck
        if ($role['slug'] === 'driver') {
            return $truck['assigned_driver_id'] == $userId;
        }
        
        return false;
    }
    
    /**
     * Check driver access
     * 
     * @param int $userId
     * @param array $role
     * @param string $permission
     * @param int $driverId
     * @return bool
     */
    private function checkDriverAccess(int $userId, array $role, string $permission, int $driverId): bool
    {
        // Users can access their own driver profile
        if ($userId == $driverId) {
            return true;
        }
        
        // Admins and fleet managers can access all drivers
        if (in_array($role['slug'], ['admin', 'super-admin', 'fleet_manager', 'dispatcher'])) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Check client access
     * 
     * @param int $userId
     * @param array $role
     * @param string $permission
     * @param int $clientId
     * @return bool
     */
    private function checkClientAccess(int $userId, array $role, string $permission, int $clientId): bool
    {
        // Users can access their own client profile
        if ($userId == $clientId) {
            return true;
        }
        
        // Admins and fleet managers can access all clients
        if (in_array($role['slug'], ['admin', 'super-admin', 'fleet_manager', 'dispatcher'])) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Check general ownership
     * 
     * @param int $userId
     * @param string $resourceType
     * @param int $resourceId
     * @return bool
     */
    private function checkOwnership(int $userId, string $resourceType, int $resourceId): bool
    {
        // Try to determine ownership from common patterns
        $ownershipMap = [
            'user' => 'id',
            'profile' => 'user_id',
            'document' => 'user_id',
            'note' => 'created_by'
        ];
        
        if (!isset($ownershipMap[$resourceType])) {
            return false;
        }
        
        $field = $ownershipMap[$resourceType];
        
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as owned
            FROM {$resourceType}s
            WHERE id = ? AND {$field} = ?
        ");
        $stmt->execute([$resourceId, $userId]);
        
        return (bool) $stmt->fetch()['owned'];
    }
    
    /**
     * Guess resource type from permission name
     * 
     * @param string $permission
     * @return string
     */
    private function guessResourceType(string $permission): string
    {
        $parts = explode('_', $permission);
        
        // Common patterns: view_order, edit_truck, delete_client, etc.
        if (count($parts) >= 2) {
            return end($parts);
        }
        
        return 'unknown';
    }
    
    /**
     * Get user's company ID
     * 
     * @param int $userId
     * @return int|null
     */
    private function getUserCompanyId(int $userId): ?int
    {
        $stmt = $this->db->prepare("
            SELECT courier_company_id FROM users WHERE id = ?
        ");
        $stmt->execute([$userId]);
        
        return $stmt->fetchColumn() ?: null;
    }
    
    /**
     * Log denied access
     * 
     * @param int $userId
     * @param array $role
     * @param string $permission
     * @param mixed $resource
     * @param string $reason
     */
    private function logDeniedAccess(int $userId, array $role, string $permission, $resource = null, string $reason = 'permission_missing'): void
    {
        $this->logger->logAccess('access_denied', [
            'user_id' => $userId,
            'role_id' => $role['id'],
            'role_name' => $role['name'],
            'permission' => $permission,
            'resource' => $resource,
            'reason' => $reason,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            'uri' => $_SERVER['REQUEST_URI'] ?? 'unknown'
        ], SecurityLogger::LEVEL_WARNING);
    }
    
    /**
     * Get all available permissions
     * 
     * @return array
     */
    public function getAllPermissions(): array
    {
        $stmt = $this->db->query("
            SELECT id, name, module, description
            FROM permissions
            ORDER BY module, name
        ");
        
        return $stmt->fetchAll();
    }
    
    /**
     * Get all roles
     * 
     * @return array
     */
    public function getAllRoles(): array
    {
        $stmt = $this->db->query("
            SELECT id, name, slug, level, is_super, description
            FROM roles
            ORDER BY level DESC
        ");
        
        return $stmt->fetchAll();
    }
    
    /**
     * Assign permission to role
     * 
     * @param int $roleId
     * @param int $permissionId
     * @return bool
     */
    public function assignPermission(int $roleId, int $permissionId): bool
    {
        try {
            $stmt = $this->db->prepare("
                INSERT IGNORE INTO role_permissions (role_id, permission_id)
                VALUES (?, ?)
            ");
            $result = $stmt->execute([$roleId, $permissionId]);
            
            if ($result) {
                // Clear cache
                unset($this->permissionCache[$roleId]);
                
                $this->logger->logAccess('permission_assigned', [
                    'role_id' => $roleId,
                    'permission_id' => $permissionId
                ]);
            }
            
            return $result;
            
        } catch (\PDOException $e) {
            $this->logger->logAccess('permission_assign_error', [
                'role_id' => $roleId,
                'permission_id' => $permissionId,
                'error' => $e->getMessage()
            ], SecurityLogger::LEVEL_ERROR);
            
            return false;
        }
    }
    
    /**
     * Remove permission from role
     * 
     * @param int $roleId
     * @param int $permissionId
     * @return bool
     */
    public function removePermission(int $roleId, int $permissionId): bool
    {
        $stmt = $this->db->prepare("
            DELETE FROM role_permissions
            WHERE role_id = ? AND permission_id = ?
        ");
        $result = $stmt->execute([$roleId, $permissionId]);
        
        if ($result) {
            // Clear cache
            unset($this->permissionCache[$roleId]);
            
            $this->logger->logAccess('permission_removed', [
                'role_id' => $roleId,
                'permission_id' => $permissionId
            ]);
        }
        
        return $result;
    }
    
    /**
     * Get users by role
     * 
     * @param int $roleId
     * @return array
     */
    public function getUsersByRole(int $roleId): array
    {
        $stmt = $this->db->prepare("
            SELECT id, username, email, first_name, last_name
            FROM users
            WHERE role_id = ?
            ORDER BY last_name, first_name
        ");
        $stmt->execute([$roleId]);
        
        return $stmt->fetchAll();
    }
    
    /**
     * Clear permission cache
     * 
     * @param int|null $roleId
     */
    public function clearCache(?int $roleId = null): void
    {
        if ($roleId) {
            unset($this->permissionCache[$roleId]);
            unset($this->roleCache[$roleId]);
        } else {
            $this->permissionCache = [];
            $this->roleCache = [];
        }
        
        $this->logger->logAccess('cache_cleared', [
            'role_id' => $roleId
        ]);
    }
}