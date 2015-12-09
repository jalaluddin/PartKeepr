<?php
namespace PartKeepr\AuthBundle\Services;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\QueryBuilder;
use FOS\UserBundle\Model\UserManagerInterface;
use FOS\UserBundle\Util\UserManipulator;
use PartKeepr\AuthBundle\Entity\User;
use PartKeepr\AuthBundle\Entity\UserProvider;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

class UserService
{
    /**
     * @var TokenStorage
     */
    private $tokenStorage;

    /**
     * @var EntityManager
     */
    private $entityManager;

    /**
     * @var UserManipulator
     */
    private $userManipulator;

    /**
     * @var UserManagerInterface
     */
    private $userManager;

    const BUILTIN_PROVIDER = "Builtin";

    public function __construct(
        TokenStorage $tokenStorage,
        EntityManager $entityManager,
        UserManipulator $userManipulator,
        UserManagerInterface $userManager
    ) {
        $this->tokenStorage = $tokenStorage;
        $this->entityManager = $entityManager;
        $this->userManipulator = $userManipulator;
        $this->userManager = $userManager;
    }

    /**
     * Returns the PartKeeprUser based on the user token within the Symfony2 environment.
     *
     * @return User The proxy user
     */
    public function getUser()
    {
        $tokenProvider = $this->tokenStorage->getToken()->getAttribute("provider");

        $provider = $this->getProvider($tokenProvider);
        $username = $this->tokenStorage->getToken()->getUsername();

        return $this->getProxyUser($username, $provider, true);
    }

    public function getProvider($providerClass)
    {
        $providerType = $this->getProviderTypeByClass($providerClass);

        return $this->getProviderByType($providerType);
    }

    public function getProviderTypeByClass($providerClass)
    {
        switch ($providerClass) {
            case 'Escape\WSSEAuthenticationBundle\Security\Core\Authentication\Provider\Provider':
                return self::BUILTIN_PROVIDER;
                break;
            case 'Symfony\Component\Security\Core\Authentication\Provider\DaoAuthenticationProvider':
                return self::BUILTIN_PROVIDER;
                break;
            case 'FR3D\LdapBundle\Security\Authentication\LdapAuthenticationProvider':
                return "LDAP";
                break;
            default:
                throw new \Exception("Unknown provider ".$providerClass);
        }
    }

    /**
     * Syncronizes the data of the given user with the FOSRestBundle
     *
     * @throws \Exception If the password was not set
     *
     * @param $user
     */
    public function syncData(User $user)
    {
        if ($user->getProvider()->getType() !== self::BUILTIN_PROVIDER) {
            return;
        }

        $FOSUser = $this->userManager->findUserByUsername($user->getUsername());

        if ($FOSUser === null) {

            if ($user->getNewPassword() == "") {
                throw new \Exception("Password must be set");
            }

            $FOSUser = $this->userManipulator->create($user->getUsername(), $user->getNewPassword(), "", true, false);
            $user->setLegacy(false);
        }
        if ($user->getNewPassword() != "") {
            $this->userManipulator->changePassword($user->getUsername(), $user->getNewPassword());
        }


        $FOSUser->setEmail($user->getEmail());
        $FOSUser->setEnabled($user->isActive());
    }

    public function getProviderByType($type)
    {
        $provider = $this->entityManager->getRepository("PartKeeprAuthBundle:UserProvider")->findOneBy(array("type" => $type));

        if ($provider !== null) {
            return $provider;
        }

        $provider = new UserProvider();
        $provider->setType($type);

        if ($type !== self::BUILTIN_PROVIDER) {
            $provider->setEditable(false);
        }

        $this->entityManager->persist($provider);
        $this->entityManager->flush();

        return $provider;
    }

    public function getBuiltinProvider()
    {
        return $this->getProviderByType(self::BUILTIN_PROVIDER);
    }

    /**
     * Returns the proxy user for a given username and provider.
     *
     * @param            $username
     * @param            $provider
     * @param bool|false $create If set to true
     *
     * @return mixed|User
     * @throws NoResultException
     * @throws \Doctrine\ORM\NonUniqueResultException
     */
    public function getProxyUser($username, UserProvider $provider, $create = false)
    {
        /**
         * @var QueryBuilder $queryBuilder
         */
        $queryBuilder = $this->entityManager->createQueryBuilder();

        $queryBuilder->select("u")
            ->from("PartKeeprAuthBundle:User", "u")
            ->where("u.provider = :provider")
            ->andWhere("u.username = :username")
            ->setParameter("provider", $provider)
            ->setParameter("username", $username);

        $query = $queryBuilder->getQuery();

        try {
            $user = $query->getSingleResult();

            return $user;
        } catch (NoResultException $e) {
            if ($create === false) {
                throw $e;
            } else {
                return $this->createProxyUser($username, $provider);
            }
        }

    }

    private function createProxyUser($username, $provider)
    {
        $user = new User($username, $provider);
        $user->setLegacy(false);
        $user->setProtected(false);
        $user->setActive(true);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    /**
     * Protects a given user against changes.
     * @param User $user
     */
    public function protect(User $user)
    {
        $user->setProtected(true);
        $this->entityManager->flush();
    }

    /**
     * Unprotects a given user against changes.
     * @param User $user
     */
    public function unprotect(User $user)
    {
        $user->setProtected(false);
        $this->entityManager->flush();
    }
}
