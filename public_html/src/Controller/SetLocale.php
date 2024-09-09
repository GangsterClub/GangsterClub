<?PHP

declare(strict_types=1);

namespace src\Controller;

class SetLocale extends Controller
{
    /**
     * Summary of __invoke
     * @param \app\Http\Request $request
     * @return string|null
     */
    public function __invoke(\app\Http\Request $request): ?string
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
            $this->redirectPrevRoute($request);
        }
    }
}
