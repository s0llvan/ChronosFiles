<?php

namespace App\Event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\AuthenticationEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Event\AuthenticationFailureEvent;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;
use Symfony\Component\Security\Http\SecurityEvents;
use Symfony\Component\DependencyInjection\ContainerInterface;
use App\Entity\User;
use Defuse\Crypto\KeyProtectedByPassword;

class SecuritySubscriber implements EventSubscriberInterface
{
	private $container;
	private $entityManager;
	private $tokenStorage;
	private $authenticationUtils;

	public function __construct(ContainerInterface $container, TokenStorageInterface $tokenStorage, AuthenticationUtils $authenticationUtils)
	{
		$this->container = $container;
		$this->entityManager = $container->get('doctrine.orm.entity_manager');
		$this->tokenStorage = $tokenStorage;
		$this->authenticationUtils = $authenticationUtils;
	}

	public static function getSubscribedEvents()
	{
		return array(
			AuthenticationEvents::AUTHENTICATION_FAILURE => 'onAuthenticationFailure',
			SecurityEvents::INTERACTIVE_LOGIN => 'onSecurityInteractiveLogin',
		);
	}

	public function onAuthenticationFailure(AuthenticationFailureEvent $event)
	{
		$username = $this->authenticationUtils->getLastUsername();
		$existingUser = $this->entityManager->getRepository(User::class)->findOneBy(['username' => $username]);
		if ($existingUser) {
			if ($existingUser->getEmailConfirmed()) {
				error_log("Log In Denied: Wrong password for User #" . $existingUser->getId()  . " (" . $existingUser->getEmail() . ")");
			}
		} else {
			error_log("Log In Denied: User doesn't exist: " . $username);
		}
	}

	public function onSecurityInteractiveLogin(InteractiveLoginEvent $event)
	{
		$user = $this->tokenStorage->getToken()->getUser();

		// Update your field here.
		$user->setLastLogin(new \DateTime());

		// Persist the data to database.
		$this->entityManager->persist($user);
		$this->entityManager->flush();

		$user_key_encoded = $user->getEncryptionKey();

		$password = $event->getRequest()->request->get('password');
		$password = hash('sha512', $password);

		$protected_key = KeyProtectedByPassword::loadFromAsciiSafeString($user_key_encoded);
		$user_key = $protected_key->unlockKey($password);
		$user_key_encoded = $user_key->saveToAsciiSafeString();

		$session = $this->container->get('session');
		$session->set('encryption_key', $user_key_encoded);
	}
}
