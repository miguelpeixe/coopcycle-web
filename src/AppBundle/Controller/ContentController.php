<?php

namespace AppBundle\Controller;

use League\Flysystem\Filesystem;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/{_locale}", requirements={ "_locale": "%locale_regex%" })
 */
class ContentController extends AbstractController
{
    /**
     * @Route({
     *   "ca": "/sobre-nosaltres",
     *   "de": "/uber-uns",
     *   "en": "/about-us",
     *   "es": "/sobre-nosotros",
     *   "fr": "/a-propos",
     *   "it": "/riguardo-a-noi",
     *   "pl": "/o-nas"
     * }, name="about_us")
     */
    public function aboutUsAction(Request $request, Filesystem $assetsFilesystem, CacheInterface $appCache)
    {
        if (!$assetsFilesystem->has('about_us.md')) {
            throw $this->createNotFoundException();
        }

        $aboutUs = $appCache->get('content.about_us', function (ItemInterface $item) use ($assetsFilesystem) {

            $item->expiresAfter(300);

            return $assetsFilesystem->read('about_us.md');
        });

        return $this->render('content/about_us.html.twig', [
            'about_us' => $aboutUs,
        ]);
    }
}
