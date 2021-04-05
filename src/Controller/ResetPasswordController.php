<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use App\Form\ResetPasswordType;
use App\Form\ResetPasswordNewType;
use App\Repository\UserRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;
use Defuse\Crypto\KeyProtectedByPassword;

class ResetPasswordController extends AbstractController
{
    /**
     * @Route("/reset-password", name="reset_password")
     */
    public function index(Request $request, UserRepository $userRepository, \Swift_Mailer $mailer)
    {
        $session = $request->getSession();
        $form = $this->createForm(ResetPasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            $user = $userRepository->findOneBy([
                'username' => $form->get('username')->getData(),
                'email' => $form->get('email')->getData()
            ]);

            if ($user) {

                $dateNow = new \DateTime();
                $dateDiff = $dateNow->getTimestamp();

                if ($user->getPasswordResetTokenLast()) {
                    $dateDiff = $dateNow->getTimestamp() - $user->getPasswordResetTokenLast()->getTimestamp();

                    // Hours
                    $dateDiff = $dateDiff / 60 / 60;
                }

                if ($dateDiff > 24) {

                    $token = bin2hex(random_bytes(32));
                    $user->setPasswordResetToken($token);
                    $user->setPasswordResetTokenLast($dateNow);

                    $em = $this->getDoctrine()->getManager();
                    $em->flush();

                    $confirmation_link = $this->generateUrl('reset_password_confirmation', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL);

                    $message = (new \Swift_Message('ChronosFiles - Reset password'))
                        ->setFrom('donotreply@chronosfiles.fr')
                        ->setTo($user->getEmail())
                        ->setBody(
                            $this->renderView(
                                'emails/reset_password.html.twig',
                                [
                                    'user' => $user,
                                    'confirmation_link' => $confirmation_link
                                ]
                            ),
                            'text/html'
                        );
                    $mailer->send($message);

                    $session->getFlashBag()->add('success', 'Please click on the link sent by email to reset your password');
                } else {
                    $session->getFlashBag()->add('error', 'You have already reset your password, please wait 24 hours');
                }
            } else {
                $session->getFlashBag()->add('error', 'User not found');
            }
        }

        return $this->render('reset_password/index.html.twig', [
            'form' => $form->createView()
        ]);
    }

    /**
     * @Route("/reset-password/{token}", name="reset_password_confirmation")
     */
    public function registerConfirmationAction(Request $request, $token, UserRepository $userRepository, UserPasswordEncoderInterface $passwordEncoder)
    {
        if (!$user = $userRepository->findOneBy([
            'password_reset_token' => $token
        ])) {
            return $this->redirectToRoute('index');
        }

        $em = $this->getDoctrine()->getManager();

        $form = $this->createForm(ResetPasswordNewType::class);
        $form->get('username')->setData($user->getUsername());

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            $user->setPasswordResetToken(null);
            $user->setPasswordResetTokenLast(null);

            $formPassword = $form->get('password')->getData();

            $password = hash('sha512', $formPassword);

            $protected_key = KeyProtectedByPassword::createRandomPasswordProtectedKey($password);
            $protected_key_encoded = $protected_key->saveToAsciiSafeString();

            $user->setEncryptionKey($protected_key_encoded);

            $password = $passwordEncoder->encodePassword($user, $formPassword);
            $user->setPassword($password);

            foreach ($user->getFiles() as $file) {

                $filePath = $this->getParameter('upload_directory') . $file->getFileNameLocation();

                if (file_exists($filePath)) {
                    unlink($filePath);
                }

                $em->remove($file);
            }

            $this->get('session')->getFlashBag()->add('success', 'You have successfully changed your password, now you can log-in');
        }

        $em->flush();

        return $this->render('reset_password/new_password.html.twig', [
            'form' => $form->createView()
        ]);
    }
}
