<?php

namespace App\DataFixtures;

use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;
use Defuse\Crypto\KeyProtectedByPassword;
use App\Entity\Role;
use Doctrine\Persistence\ObjectManager as PersistenceObjectManager;

class AppFixtures extends Fixture
{
	private $passwordEncoder;

	public function __construct(UserPasswordEncoderInterface $passwordEncoder)
	{
		$this->passwordEncoder = $passwordEncoder;
	}

	public function load(PersistenceObjectManager $manager)
	{
		$roles = [];

		foreach ($this->getRoleData() as [$name, $slug, $fileSizeLimit, $storageSizeLimit]) {
			$role = new Role();
			$role->setName($name);
			$role->setSlug($slug);
			$role->setUploadFileSizeLimit($fileSizeLimit);
			$role->setStorageSizeLimit($storageSizeLimit);

			$manager->persist($role);

			$roles[$slug] = $role;
		}

		foreach ($this->getUserData() as [$username, $password, $email, $role]) {
			$user = new User();
			$user->setUsername($username);
			$user->setPassword($this->passwordEncoder->encodePassword($user, $password));
			$user->setEmail($email);
			$user->setRole($roles[$role]);
			$user->setEmailConfirmed(true);

			$password = hash('sha512', $password);

			$protected_key = KeyProtectedByPassword::createRandomPasswordProtectedKey($password);
			$protected_key_encoded = $protected_key->saveToAsciiSafeString();

			$user->setEncryptionKey($protected_key_encoded);

			$manager->persist($user);
			$this->addReference($username, $user);
		}

		$manager->flush();
	}

	private function getUserData(): array
	{
		return [
			// $userData = [$username, $password, $email, $role];
			['super_admin', 'super_admin', 'super_admin@local.host', 'ROLE_SUPER_ADMIN'],
			['admin', 'admin', 'admin@local.host', 'ROLE_ADMIN'],
			['user', 'user', 'user@local.host', 'ROLE_USER'],
		];
	}

	private function getRoleData(): array
	{
		return [
			// $userData = [$slug, $name, $fileSizeLimit, $storageSizeLimit];
			['Super Administrator', 'ROLE_SUPER_ADMIN', null, null],
			['Administrator', 'ROLE_ADMIN', 10240000, 102400000],
			['User', 'ROLE_USER', 1024000, 10240000],
		];
	}
}
