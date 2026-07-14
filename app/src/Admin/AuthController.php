<?php

declare(strict_types=1);

namespace App\Admin;

use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Service\Auth\AuthService;
use App\Service\ValidationException;
use App\View\View;

final class AuthController extends AdminController
{
    public function __construct(
        View $view,
        Session $session,
        private readonly AuthService $auth,
    ) {
        parent::__construct($view, $session);
    }

    public function showLogin(Request $request): ResponseInterface
    {
        if ($this->session->isAdmin()) {
            return Response::redirect('/admin');
        }

        return $this->render('admin/login', ['title' => 'Admin-Login', 'error' => null]);
    }

    public function login(Request $request): ResponseInterface
    {
        $username = trim((string) ($request->post['username'] ?? ''));
        $password = (string) ($request->post['password'] ?? '');

        $result = $this->auth->attempt($username, $password);

        if ($result->isAdmin) {
            $this->session->loginAdmin((int) $result->adminId, (string) $result->username);

            return Response::redirect('/admin');
        }

        if ($result->isBootstrap) {
            $this->session->loginBootstrap();

            return Response::redirect('/admin/setup');
        }

        return $this->render('admin/login', [
            'title' => 'Admin-Login',
            'error' => 'Benutzername oder Passwort ist falsch.',
        ], 401);
    }

    public function showSetup(Request $request): ResponseInterface
    {
        if (!$this->session->isBootstrap()) {
            return Response::redirect($this->session->isAdmin() ? '/admin' : '/admin/login');
        }

        return $this->render('admin/setup', [
            'title' => 'Ersten Admin anlegen',
            'errors' => [],
            'values' => [],
        ]);
    }

    public function setup(Request $request): ResponseInterface
    {
        if (!$this->session->isBootstrap()) {
            return Response::redirect($this->session->isAdmin() ? '/admin' : '/admin/login');
        }

        $username = trim((string) ($request->post['username'] ?? ''));

        try {
            $adminId = $this->auth->createFirstAdmin(
                $username,
                (string) ($request->post['password'] ?? ''),
                (string) ($request->post['password_repeat'] ?? ''),
            );
        } catch (ValidationException $e) {
            return $this->render('admin/setup', [
                'title' => 'Ersten Admin anlegen',
                'errors' => $e->getErrors(),
                'values' => ['username' => $username],
            ], 422);
        }

        $this->session->loginAdmin($adminId, $username);
        $this->session->flash('Admin-Konto angelegt. Die Bootstrap-Zugangsdaten sind ab jetzt ungültig.');

        return Response::redirect('/admin');
    }

    public function logout(Request $request): ResponseInterface
    {
        $this->session->logout();

        return Response::redirect('/admin/login');
    }
}
