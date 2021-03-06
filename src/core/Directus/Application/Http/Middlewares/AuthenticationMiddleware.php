<?php

namespace Directus\Application\Http\Middlewares;

use Directus\Application\Container;
use Directus\Application\Http\Request;
use Directus\Application\Http\Response;
use Directus\Authentication\Exception\UserNotAuthenticatedException;
use Directus\Authentication\User\User;
use Directus\Authentication\User\UserInterface;
use Directus\Database\TableGateway\BaseTableGateway;
use Directus\Database\TableGateway\DirectusPermissionsTableGateway;
use Directus\Database\TableGatewayFactory;
use Directus\Exception\UnauthorizedException;
use Directus\Permissions\Acl;
use Directus\Services\AuthService;
use Zend\Db\Sql\Select;
use Zend\Db\TableGateway\TableGateway;

class AuthenticationMiddleware extends AbstractMiddleware
{
    /**
     * @param Request $request
     * @param Response $response
     * @param callable $next
     *
     * @return Response
     *
     * @throws UnauthorizedException
     * @throws UserNotAuthenticatedException
     */
    public function __invoke(Request $request, Response $response, callable $next)
    {
        // TODO: Improve this, move back from api.php to make the table gateway work with its dependency
        $container = $this->container;
        \Directus\Database\SchemaService::setAclInstance($container->get('acl'));
        \Directus\Database\SchemaService::setConnectionInstance($container->get('database'));
        \Directus\Database\SchemaService::setConfig($container->get('config'));
        BaseTableGateway::setHookEmitter($container->get('hook_emitter'));
        BaseTableGateway::setContainer($container);
        TableGatewayFactory::setContainer($container);

        $container['app.settings'] = function (Container $container) {
            $dbConnection = $container->get('database');
            $DirectusSettingsTableGateway = new \Zend\Db\TableGateway\TableGateway('directus_settings', $dbConnection);
            $rowSet = $DirectusSettingsTableGateway->select();

            $settings = [];
            foreach ($rowSet as $setting) {
                $settings[$setting['scope']][$setting['key']] = $setting['value'];
            }

            return $settings;
        };

        // TODO: Move this to middleware
        $whitelisted = ['auth/authenticate'];
        if (in_array($request->getUri()->getPath(), $whitelisted)) {
            return $next($request, $response);
        }

        $user = $this->authenticate($request);
        $publicRoleId = $this->getPublicRoleId();
        if (!$user && !$publicRoleId) {
            throw new UserNotAuthenticatedException();
        }

        // =============================================================================
        // Set authenticated user permissions
        // =============================================================================
        $hookEmitter = $this->container->get('hook_emitter');

        if (!$user && $publicRoleId) {
            // NOTE: 0 will not represent a "guest" or the "public" user
            // To prevent the issue where user column on activity table can't be null
            $user = new User([
                'id' => 0
            ]);
        }

        // TODO: Set if the authentication was a public or not? options array
        $hookEmitter->run('directus.authenticated', [$user]);
        $hookEmitter->run('directus.authenticated.token', [$user]);

        // Reload all user permissions
        // At this point ACL has run and loaded all permissions
        // This behavior works as expected when you are logged to the CMS/Management
        // When logged through API we need to reload all their permissions
        $dbConnection = $this->container->get('database');
        $permissionsTable = new DirectusPermissionsTableGateway($dbConnection, null);
        $permissionsByCollection = $permissionsTable->getUserPermissions($user->getId());
        $rolesIpWhitelist = $this->getRolesIPWhitelist();

        /** @var Acl $acl */
        $acl = $this->container->get('acl');
        $acl->setPermissions($permissionsByCollection);
        $acl->setRolesIpWhitelist($rolesIpWhitelist);
        // TODO: Adding an user should auto set its ID and GROUP
        // TODO: User data should be casted to its data type
        // TODO: Make sure that the group is not empty
        $acl->setUserId($user->getId());
        if (!$user && $publicRoleId) {
            $acl->setPublic($publicRoleId);
        }

        if (!$acl->isIpAllowed(get_request_ip())) {
            throw new UnauthorizedException('Request not allowed from IP address');
        }

        return $next($request, $response);
    }

    /**
     * Tries to authenticate the user based on the HTTP Request
     *
     * @param Request $request
     *
     * @return UserInterface
     */
    protected function authenticate(Request $request)
    {
        $user = null;
        $authToken = $this->getAuthToken($request);

        if ($authToken) {
            /** @var AuthService $authService */
            $authService = $this->container->get('services')->get('auth');

            $user = $authService->authenticateWithToken($authToken);
        }

        return $user;
    }

    /**
     * Gets the authentication token from the request
     *
     * @param Request $request
     *
     * @return string
     */
    protected function getAuthToken(Request $request)
    {
        $authToken = null;

        if ($request->getParam('access_token')) {
            $authToken = $request->getParam('access_token');
        } elseif ($request->hasHeader('Php-Auth-User')) {
            $authUser = $request->getHeader('Php-Auth-User');
            $authPassword = $request->getHeader('Php-Auth-Pw');

            if (is_array($authUser)) {
                $authUser = array_shift($authUser);
            }

            if (is_array($authPassword)) {
                $authPassword = array_shift($authPassword);
            }

            if ($authUser && (empty($authPassword) || $authUser === $authPassword)) {
                $authToken = $authUser;
            }
        } elseif ($request->hasHeader('Authorization')) {
            $authorizationHeader = $request->getHeader('Authorization');

            // If there's multiple Authorization header, pick first, ignore the rest
            if (is_array($authorizationHeader)) {
                $authorizationHeader = array_shift($authorizationHeader);
            }

            if (is_string($authorizationHeader) && preg_match("/Bearer\s+(.*)$/i", $authorizationHeader, $matches)) {
                $authToken = $matches[1];
            }
        }

        return $authToken;
    }

    /**
     * Gets the public role id if exists
     *
     * @return int|null
     */
    protected function getPublicRoleId()
    {
        $dbConnection = $this->container->get('database');
        $directusGroupsTableGateway = new TableGateway('directus_roles', $dbConnection);
        $publicRole = $directusGroupsTableGateway->select(['name' => 'public'])->current();

        $roleId = null;
        if ($publicRole) {
            $roleId = $publicRole['id'];
        }

        return $roleId;
    }

    /**
     * Gets IP whitelist
     *
     * @return array
     */
    protected function getRolesIpWhitelist()
    {
        $dbConnection = $this->container->get('database');
        $directusGroupsTableGateway = new TableGateway('directus_roles', $dbConnection);
        $select = new Select($directusGroupsTableGateway->table);
        $select->columns(['id', 'ip_whitelist']);
        $select->limit(1);

        $result = $directusGroupsTableGateway->selectWith($select);

        $list = [];
        foreach ($result as $row) {
            $list[$row['id']] = array_filter(preg_split('/,\s*/', $row['ip_whitelist']));
        }

        return $list;
    }
}
