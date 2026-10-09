<?php
declare(strict_types=1);

namespace Picklers\Web\Controller;

use Picklers\Repositories\FacilityRepository;
use Picklers\Repositories\MatchRepository;
use Symfony\Component\Asset\Packages;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** The public landing page. */
final class HomeController extends AbstractWebController
{
    private const BRANDS = [
        'joola.png' => 'JOOLA Pickleball',
        'selkirk.png' => 'Selkirk Sport',
        'crbn.png' => 'CRBN Pickleball',
        'franklin.png' => 'Franklin Sports',
        'wilson.png' => 'Wilson Athletic',
        'head.svg' => 'HEAD Pickleball',
        'gearbox.png' => 'Gearbox Sports',
        'holbrook.png' => 'Holbrook Pickleball',
        'vatic-pro.png' => 'Vatic Pro',
        'bread-butter.png' => 'Bread & Butter',
        'six-zero.png' => 'Six Zero Pickleball',
        'palakol-performance.png' => 'Palakol Performance',
    ];

    private const FAQS = [
        ['How do I book a pickleball court on Picklers?', 'Search for your preferred facility, pick an open court time slot, choose your payment method, and confirm. Your booking code will be instantly generated!'],
        ['What is Open Play and how do I join?', 'Open Play allows solo players or small groups to join existing queues at partner courts easily with other pickleball enthusiasts.'],
        ['Can I list my own court or facility on Picklers?', 'Yes! Click "List Your Court" in the navigation bar to submit your facility details. Our team reviews every application and will notify you once your portal is activated.'],
    ];

    public function __construct(
        private readonly FacilityRepository $facilities,
        private readonly MatchRepository $matches,
        private readonly Packages $assets,
    ) {
    }

    #[Route('/', name: 'home', methods: ['GET'])]
    #[Route('/index.php', name: 'home_php', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('web/home.html.twig', [
            'currentUser' => $this->sessionUser()?->row(),
            'facilities' => $this->facilities->getFacilities('', 'All', 'recommended'),
            'matches' => $this->matches->getMatches(),
            'brands' => array_map(
                fn(string $file, string $label) => ['logo' => $this->assets->getUrl('assets/brand-logos/' . $file), 'label' => $label],
                array_keys(self::BRANDS),
                self::BRANDS
            ),
            'faqs' => array_map(static fn(array $f) => ['q' => $f[0], 'a' => $f[1]], self::FAQS),
            'site_name' => 'PICKLERS',
            'tagline' => '#1 Philippines Pickleball Booking App',
        ]);
    }
}
