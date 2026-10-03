<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\MasterDataService;
use App\Support\Csrf;
use App\Support\Exception\NotFoundException;
use App\Support\Exception\ValidationException;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\View;

/**
 * Category product. Admin saja - ditegakkan route table.
 *
 * Category tidak memiliki status aktif pada model sumber (§1.3), sehingga
 * tidak ada aksi deactivate di sini.
 */
final class CategoryController
{
    public function __construct(
        private readonly View $view,
        private readonly MasterDataService $masterData,
        private readonly Session $session,
        private readonly Csrf $csrf,
    ) {
    }

    public function index(Request $request): Response
    {
        return Response::html($this->view->render('categories/index', [
            'title'      => 'Categories',
            'activeNav'  => 'products',
            'categories' => $this->masterData->allCategories(),
        ]));
    }

    public function create(Request $request): Response
    {
        return Response::html($this->renderForm());
    }

    public function store(Request $request): Response
    {
        try {
            $this->masterData->createCategory($request->bodyAll());
        } catch (ValidationException $e) {
            return Response::html($this->renderForm(null, $request->bodyAll(), $e->errors()), 422);
        }

        $this->session->flash('success', 'Category created.');

        return Response::redirect('/categories');
    }

    public function edit(Request $request): Response
    {
        $category = $this->masterData->requireCategory($this->requireId($request));

        return Response::html($this->renderForm($category));
    }

    public function update(Request $request): Response
    {
        $id = $this->requireId($request);
        $category = $this->masterData->requireCategory($id);

        try {
            $this->masterData->updateCategory($id, $request->bodyAll());
        } catch (ValidationException $e) {
            return Response::html($this->renderForm($category, $request->bodyAll(), $e->errors()), 422);
        }

        $this->session->flash('success', 'Category updated.');

        return Response::redirect('/categories');
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, string> $errors
     */
    private function renderForm(?object $category = null, array $old = [], array $errors = []): string
    {
        return $this->view->render('categories/form', [
            'title'     => $category === null ? 'Create category' : 'Edit category',
            'activeNav' => 'products',
            'category'  => $category,
            'old'       => $old,
            'errors'    => $errors,
            'csrf'      => $this->csrf,
        ]);
    }

    private function requireId(Request $request): int
    {
        $id = $request->routeParamInt('id');

        if ($id === null) {
            throw new NotFoundException();
        }

        return $id;
    }
}
