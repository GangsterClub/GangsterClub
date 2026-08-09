<?PHP

declare(strict_types=1);

namespace src\Controller;

use app\Http\Response;

class SetLocale extends Controller
{
    public function __invoke(\app\Http\Request $request): Response
    {
        $locale = ($request->getRouteParameter('locale') ?? null);
        if ($locale !== null) {
            $locale = urldecode($locale);
        }

        $session = $this->application->get(\app\Service\SessionService::class);
        $translationService = $this->application->get(\app\Service\TranslationService::class);
        if (array_key_exists($locale, $translationService->getSupportedLanguages()) === true) {
            $translationService->setLocale($locale);
            $session->set('preferred_language', $locale);
        }
        return $this->redirectPrevRoute($request);
    }
}
