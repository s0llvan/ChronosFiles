<?php

namespace App\Controller;

use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;
use Symfony\Component\HttpFoundation\Request;
use App\Entity\User;
use App\Form\UserType;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use App\Events;
use Defuse\Crypto\KeyProtectedByPassword;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use App\Repository\UserRepository;
use App\Repository\RoleRepository;
use App\Entity\Role;

class RegistrationController extends AbstractController
{
	/**
	 * @Route("/register", name="register")
	 */
	public function registerAction(Request $request, UserPasswordEncoderInterface $passwordEncoder, EventDispatcherInterface $eventDispatcher, \Swift_Mailer $mailer, UserRepository $userRepository, RoleRepository $roleRepository)
	{
		$user = new User();
		$form = $this->createForm(UserType::class, $user);
		$form->handleRequest($request);

		if ($form->isSubmitted() && $form->isValid()) {

			$token = bin2hex(random_bytes(32));

			$user->setEmailConfirmationToken($token);

			$password = $passwordEncoder->encodePassword($user, $user->getPassword());
			$user->setPassword($password);

			$slug = 'ROLE_USER';

			if ($userRepository->count([]) <= 0) {
				$slug = 'ROLE_SUPER_ADMIN';
			}

			$entityManager = $this->getDoctrine()->getManager();

			$role = $roleRepository->findOneBySlug($slug);

			if (!$role) {
				$role = new Role();
				$role->setName('User');
				$role->setSlug('ROLE_USER');
				$role->setUploadFileSizeLimit(10240);
				$role->setStorageSizeLimit(307200);

				$entityManager->persist($role);
			}

			$user->setRole($role);

			$password = $form->get('password')->getData();
			$password = hash('sha512', $password);

			$protected_key = KeyProtectedByPassword::createRandomPasswordProtectedKey($password);
			$protected_key_encoded = $protected_key->saveToAsciiSafeString();

			$user->setEncryptionKey($protected_key_encoded);

			$entityManager->persist($user);
			$entityManager->flush();

			$confirmation_link = $this->generateUrl('register_confirmation', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL);

			$message = (new \Swift_Message('ChronosFiles - Registration'))
				->setFrom('donotreply@chronosfiles.fr')
				->setTo($user->getEmail())
				->setBody(
					$this->renderView(
						'emails/registration.html.twig',
						[
							'user' => $user,
							'confirmation_link' => $confirmation_link
						]
					),
					'text/html'
				);
			if ($mailer->send($message)) {
				$event = new GenericEvent($user);
				$eventDispatcher->dispatch(Events::USER_REGISTERED, $event);

				$this->get('session')->getFlashBag()->add('success', 'Please click on the link sent by email to confirm your account');
			} else {
				$this->get('session')->getFlashBag()->add('error', 'Email confirmation cannot be send');
			}

			return $this->redirectToRoute('register');
		}

		return $this->render('registration.html.twig', [
			'form' => $form->createView()
		]);
	}

	/**
	 * @Route("/register-confirmation/{token}", name="register_confirmation")
	 */
	public function registerConfirmationAction(Request $request, $token, UserRepository $userRepository)
	{
		if ($user = $userRepository->findOneBy(['emailConfirmationToken' => $token])) {

			$user->setEmailConfirmationToken(null);
			$user->setEmailConfirmed(true);

			$entityManager = $this->getDoctrine()->getManager();
			$entityManager->flush();

			$this->get('session')->getFlashBag()->add('success', 'Registration completed, you can now log in !');
		} else {
			$this->get('session')->getFlashBag()->add('error', 'Wrong token !');
		}

		return $this->redirectToRoute('login');
	}
}
