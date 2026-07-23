<?PHP

declare(strict_types=1);

namespace src\Controller;

use app\Http\Response;

class SetLocale extends Controller
{
    public function __invoke(\app\Http\Request $request): Response
    {
        $locale = ($request->getParameter('locale') ?? null);
        if ($locale !== null) {
            $locale = urldecode($locale);
        }

        $session = $this->application->get('sessionService');
        $translationService = $this->application->get('translationService');
        if (array_key_exists($locale, $translationService->getSupportedLanguages()) === true) {
            $translationService->setLocale($locale);
            $session->set('preferred_language', $locale);
        }
        return $this->redirectPrevRoute($request);
    }
}
