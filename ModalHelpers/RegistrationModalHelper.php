<?php namespace ModalHelpers;

use CustomFacades\Repositories\BillingPlanRepo;
use CustomFacades\Repositories\UserRepo;
use CustomFacades\Validators\RegistrationFormValidator;
use Tobuli\Entities\EmailTemplate;
use Bugsnag\BugsnagLaravel\Facades\Bugsnag as Bugsnag;
use Tobuli\Exceptions\ValidationException;
use Tobuli\Services\UserService;

class RegistrationModalHelper extends ModalHelper
{
    public function create()
    {
        $userService = new UserService();

        try {
            RegistrationFormValidator::validate('create', $this->data);
        } catch (ValidationException $e) {
            return [
                'status' => 0,
                'errors' => $e->getErrors()
            ];
        }

        $data = [
            'email'    => $this->data['email'],
            'password' => $userService->generatePassword()
        ];

        $user = $userService->registration($data);

        $this->sendRegistrationEmail($user, $data);

        return ['status' => 1, 'message' => trans('front.registration_successful')];
    }

    public function sendRegistrationEmail($user, $data)
    {
        $email_template = EmailTemplate::getTemplate('registration', $user);

        try {
            sendTemplateEmail($user->email, $email_template, $data);
        } catch (\Exception $e) {
            Bugsnag::notifyException($e);
        }
    }
}