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
    public function __invoke(\app\Http\Request $request): null
    {
        $locale = ($request->getParameter('locale') ?? null);
        if ($locale !== null) {
            $locale = urldecode($locale);
        }

        $sessionService = $this->application->get('sessionService');
        $translationService = $this->application->get('translationService');
        if (array_key_exists($locale, $translationService->getSupportedLanguages()) === true) {
            $translationService->setLocale($locale);
            $sessionService->set('preferred_language', $locale);
        }

        $this->redirect = true;
        $prevRoute = ($sessionService->get('PREV_ROUTE') ??
            (filter_input(INPUT_SERVER, 'HTTP_REFERER', FILTER_SANITIZE_URL)) ??
            (APP_BASE.'/'));

        header('Location: '.$prevRoute,  true, 301);
    }
}
