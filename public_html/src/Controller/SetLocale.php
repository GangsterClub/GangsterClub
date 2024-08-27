<?PHP

declare(strict_types=1);

namespace src\Controller;

class SetLocale extends Controller
{
    public function __invoke(\app\Http\Request $request) : string
    {
        $locale = $request->getParameter('locale') ?? null;
        if($locale !== null)
            $locale = urldecode($locale);

        $sessionService = $this->application->get('sessionService');
        $translationService = $this->application->get('translationService');
        if(array_key_exists($locale, $translationService->getSupportedLanguages()))
        {
            $translationService->setLocale($locale);
            $sessionService->set('preferred_language', $locale);
        }
        $this->redirect = true;
        return header('Location: '.$sessionService->get('PREV_ROUTE'),  true, 301);
    }
}
