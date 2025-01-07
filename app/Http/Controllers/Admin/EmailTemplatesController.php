<?php

namespace App\Http\Controllers\Admin;

use Tobuli\Entities\EmailTemplate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Response;
use Tobuli\Helpers\Templates\TemplateManager;
use Tobuli\Validation\EmailTemplateFormValidator;
use Tobuli\Repositories\EmailTemplate\EmailTemplateRepositoryInterface;

class EmailTemplatesController extends BaseController
{
    /**
     * @var EmailTemplateRepositoryInterface
     */
    private $emailTemplate;
    private $section = 'email_templates';
    /**
     * @var EmailTemplateFormValidator
     */
    private $emailTemplateFormValidator;

    function __construct(EmailTemplateRepositoryInterface $emailTemplate, EmailTemplateFormValidator $emailTemplateFormValidator)
    {
        parent::__construct();
        $this->emailTemplate = $emailTemplate;
        $this->emailTemplateFormValidator = $emailTemplateFormValidator;
    }

    public function index()
    {
        $input = Request::all();

        $input['filter']['user_id'] = $this->user->isReseller() ? $this->user->id : null;

        $items = $this->emailTemplate->searchAndPaginate($input, 'name', 'asc', 20);
        $section = $this->section;

        $canCreate = $this->user->canChangeAppearance();

        return View::make('admin::' . ucfirst($this->section) . '.' . (Request::ajax() ? 'table' : 'index'))
            ->with(compact('items', 'input', 'section', 'canCreate'));
    }

    public function create()
    {
        $this->checkException('email_templates', 'create');

        $names = EmailTemplate::notOwn($this->user)->orderBy('name')->pluck('name', 'name');

        return View::make('admin::' . ucfirst($this->section) . '.create')->with(compact('names'));
    }

    public function store()
    {
        $this->checkException('email_templates', 'store');

        $template = EmailTemplate::notOwn($this->user)
            ->where('email_templates.name', request()->get('name'))
            ->first();

        if (!$template)
            return Response::json(['status' => 0]);

        $clone = new EmailTemplate();
        $clone->user_id = $this->user->id;
        $clone->name = $template->name;
        $clone->title = $template->title;
        $clone->note = $template->note;
        $clone->save();

        return Response::json(['status' => 1]);
    }

    public function edit($id = NULL)
    {
        $item = $this->emailTemplate->find($id);

        $this->checkException('email_templates', 'edit', $item);

        $replacers = (new TemplateManager())->loadTemplateBuilder($item->name)->getPlaceholders($item);
        // Crear vista si no existe
        $directory = resource_path('views/emails');
        $filePath = $directory . '/' . $item->name . '.blade.php';

        // Verificar si el directorio existe, si no, crearlo
        if (!File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        // Verificar permisos de escritura
        if (!is_writable($directory)) {
            abort(500, "El directorio $directory no tiene permisos de escritura.");
        }

        // Verificar si el archivo existe, si no, crearlo
        if (!File::exists($filePath)) {
            File::put($filePath, ''); // Crear un archivo vacío
        }

        $content = view()->make('emails.' . $item->name)->render();

        return View::make('admin::' . ucfirst($this->section) . '.edit')->with(compact('item', 'replacers', 'content'));
    }

    public function update($id)
    {
        $input = Request::all();

        $item = $this->emailTemplate->find($id);

        $this->checkException('email_templates', 'update', $item);

        $this->emailTemplateFormValidator->validate('update', $input, $id);

        //GUARDAR VISTA DE EMAIL
        if (isset($input['content'])) {

            $directory = resource_path('views/emails');
            $url = $directory . '/' . $item->name . '.blade.php';
            // Verificar si el directorio existe, si no, crearlo
            if (!File::exists($directory)) {
                File::makeDirectory($directory, 0755, true);
            }

            try {
                // Guardar el contenido en el archivo
                File::put($url, $input['content']);
            } catch (\Exception $e) {
                // Registrar el error
                Log::error('Error al guardar el archivo: ' . $e->getMessage());
            }
        }

        $item->update($input);

        return Response::json(['status' => 1]);
    }

    public function destroy($id)
    {
        $item = $this->emailTemplate->find($id);

        $this->checkException('email_templates', 'remove', $item);

        $item->delete();

        return Response::json(['status' => 1]);
    }
}
