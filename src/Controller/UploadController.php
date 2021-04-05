<?php

namespace App\Controller;

use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use App\Entity\File;
use App\Form\FileType;
use Defuse\Crypto\File as CryptoFile;
use Defuse\Crypto\Key;
use Defuse\Crypto\Crypto;

class UploadController extends AbstractController
{
	/**
	* @Route("/upload", name="upload")
	*/
	public function index(Request $request)
	{
		$user = $this->getUser();
		
		$form = $this->createForm(FileType::class, null, [
			'user' => $user
			]
		);
		$form->handleRequest($request);
		
		if ($form->isSubmitted() && $form->isValid()) {
			
			$totalSize = $user->getUploadStorageSize();
			$files = $form->get('fileName')->getData();
			
			foreach ($files as $file) {
				$fileOriginalName = $file->getClientOriginalName();
				$fileSize = $file->getSize();
				
				if ($user->getRole()->getUploadFileSizeLimit() && $fileSize > $user->getRole()->getUploadFileSizeLimit()) {
					$this->get('session')->getFlashBag()->add('error', $fileOriginalName . ' is too large');
					return $this->redirectToRoute('upload');
				}
				
				$totalSize += $fileSize;
			}
			
			if ($user->getRole()->getStorageSizeLimit() && $totalSize > $user->getRole()->getStorageSizeLimit()) {
				$this->get('session')->getFlashBag()->add('error', 'Files size is too large');
				return $this->redirectToRoute('upload');
			}
			
			$entityManager = $this->getDoctrine()->getManager();
			
			foreach ($files as $file) {
				$fileEntity = new File();
				$fileEntity->setCategory($form->get('category')->getData());
				
				$fileOriginalName = $file->getClientOriginalName();
				$fileSize = $file->getSize();
				
				$fileName = $this->generateUniqueFileName() . '.' . $file->guessExtension();
				$tmpFileName = $fileName . '.bak';
				
				$filePath = $this->getParameter('upload_directory') . $fileName;
				$tmpFilePath = $filePath . '.bak';
				
				$file->move(
					$this->getParameter('upload_directory'),
					$tmpFileName
				);
				
				$fileHash = md5_file($tmpFilePath);
				
				$user_key_encoded = $this->get('session')->get('encryption_key');
				$user_key = Key::loadFromAsciiSafeString($user_key_encoded);
				
				CryptoFile::encryptFile($tmpFilePath, $filePath, $user_key);
				$encryptedFileName = Crypto::encrypt($fileOriginalName, $user_key);
				
				if (file_exists($tmpFilePath)) {
					unlink($tmpFilePath);
				}
				
				$fileEntity->setFileName($encryptedFileName);
				$fileEntity->setFileNameLocation($fileName);
				$fileEntity->setFileHash($fileHash);
				$fileEntity->setFileSize($fileSize);
				$fileEntity->setUser($user);
				
				$entityManager->persist($fileEntity);
			}
			
			$user->setUploadStorageSize($totalSize);
			
			$entityManager->flush();
			
			$this->get('session')->getFlashBag()->add('success', 'Upload completed !');
		}
		
		return $this->render('upload/index.html.twig', [
			'form' => $form->createView()
			]
		);
	}
	
	/**
	* @return string
	*/
	private function generateUniqueFileName()
	{
		return hash('sha512', uniqid());
	}
}
