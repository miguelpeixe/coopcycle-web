<?php

namespace AppBundle\Form;

use AppBundle\Service\SettingsManager;
use League\Flysystem\Filesystem;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormError;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Translation\TranslatorInterface;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Constraints;

class CustomizeType extends AbstractType
{
    public function __construct(
        SettingsManager $settingsManager,
        Filesystem $assetsFilesystem)
    {
        $this->settingsManager = $settingsManager;
        $this->assetsFilesystem = $assetsFilesystem;
    }

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('motto', TextType::class, [
                'required' => false,
                'label' => 'form.customize.motto.label',
                'attr' => ['placeholder' => 'index.banner'],
                'help' => 'form.customize.motto.help',
            ])
            ->add('aboutUs', TextareaType::class, [
                'required' => false,
                'label' => 'form.customize.about_us.label',
                'attr' => ['rows' => '12'],
                'help' => 'mardown_formatting.help',
            ]);

        $builder->addEventListener(FormEvents::POST_SET_DATA, function (FormEvent $event) {
            $form = $event->getForm();
            if ($this->assetsFilesystem->has('about_us.md')) {
                $aboutUs = $this->assetsFilesystem->read('about_us.md');
                $form->get('aboutUs')->setData($aboutUs);
            }

            $motto = $this->settingsManager->get('motto');
            if (!empty($motto)) {
                $form->get('motto')->setData($motto);
            }
        });

        $builder->addEventListener(FormEvents::SUBMIT, function (FormEvent $event) {
            $form = $event->getForm();
            $aboutUs = $form->get('aboutUs')->getData();
            if ($this->assetsFilesystem->has('about_us.md')) {
                $this->assetsFilesystem->update('about_us.md', $aboutUs);
            } else {
                $this->assetsFilesystem->write('about_us.md', $aboutUs);
            }

            $motto = $form->get('motto')->getData();
            if (!empty($motto)) {
                $this->settingsManager->set('motto', $motto);
                $this->settingsManager->flush();
            }
        });
    }
}
