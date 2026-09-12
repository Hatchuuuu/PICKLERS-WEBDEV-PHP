<?php
declare(strict_types=1);

namespace Picklers\Controllers;

use Picklers\Core\Request;
use Picklers\Core\Response;
use Picklers\Services\FacilityService;
use Picklers\Services\MatchService;

class HomeController extends BaseController {
    private FacilityService $facilityService;
    private MatchService $matchService;

    public function __construct(
        ?FacilityService $facilityService = null,
        ?MatchService $matchService = null
    ) {
        $this->facilityService = $facilityService ?? new FacilityService();
        $this->matchService = $matchService ?? new MatchService();
    }

    public function index(Request $request) {
        $currentUser = $this->currentUser();
        $facilities = $this->facilityService->getFacilities('', 'All', 'recommended');
        $matches = $this->matchService->getMatches();

        $brands = [
            ['logo' => \Picklers\Helpers\Url::asset('brand-logos/joola.png'), 'label' => 'JOOLA Pickleball'],
            ['logo' => \Picklers\Helpers\Url::asset('brand-logos/selkirk.png'), 'label' => 'Selkirk Sport'],
            ['logo' => \Picklers\Helpers\Url::asset('brand-logos/crbn.png'), 'label' => 'CRBN Pickleball'],
            ['logo' => \Picklers\Helpers\Url::asset('brand-logos/franklin.png'), 'label' => 'Franklin Sports'],
            ['logo' => \Picklers\Helpers\Url::asset('brand-logos/wilson.png'), 'label' => 'Wilson Athletic'],
            ['logo' => \Picklers\Helpers\Url::asset('brand-logos/head.svg'), 'label' => 'HEAD Pickleball'],
            ['logo' => \Picklers\Helpers\Url::asset('brand-logos/gearbox.png'), 'label' => 'Gearbox Sports'],
            ['logo' => \Picklers\Helpers\Url::asset('brand-logos/holbrook.png'), 'label' => 'Holbrook Pickleball'],
            ['logo' => \Picklers\Helpers\Url::asset('brand-logos/vatic-pro.png'), 'label' => 'Vatic Pro'],
            ['logo' => \Picklers\Helpers\Url::asset('brand-logos/bread-butter.png'), 'label' => 'Bread & Butter'],
            ['logo' => \Picklers\Helpers\Url::asset('brand-logos/six-zero.png'), 'label' => 'Six Zero Pickleball'],
            ['logo' => \Picklers\Helpers\Url::asset('brand-logos/palakol-performance.png'), 'label' => 'Palakol Performance']
        ];

        $faqs = [
            [
                'q' => 'How do I book a pickleball court on Picklers?',
                'question' => 'How do I book a pickleball court on Picklers?',
                'a' => 'Search for your preferred facility, pick an open court time slot, choose your payment method, and confirm. Your booking code will be instantly generated!',
                'answer' => 'Search for your preferred facility, pick an open court time slot, choose your payment method, and confirm. Your booking code will be instantly generated!'
            ],
            [
                'q' => 'What is Open Play and how do I join?',
                'question' => 'What is Open Play and how do I join?',
                'a' => 'Open Play allows solo players or small groups to join existing queues at partner courts easily with other pickleball enthusiasts.',
                'answer' => 'Open Play allows solo players or small groups to join existing queues at partner courts easily with other pickleball enthusiasts.'
            ],
            [
                'q' => 'Can I list my own court or facility on Picklers?',
                'question' => 'Can I list my own court or facility on Picklers?',
                'a' => 'Yes! Click "List Your Court" in the navigation bar to submit your facility details. Our team will verify and activate your portal within 24 hours.',
                'answer' => 'Yes! Click "List Your Court" in the navigation bar to submit your facility details. Our team will verify and activate your portal within 24 hours.'
            ]
        ];

        return $this->view('pages/home', [
            'currentUser' => $currentUser,
            'facilities' => $facilities,
            'matches' => $matches,
            'brands' => $brands,
            'faqs' => $faqs,
            'site_name' => "PICKLERS",
            'tagline' => "#1 Philippines Pickleball Booking App"
        ]);
    }
}
