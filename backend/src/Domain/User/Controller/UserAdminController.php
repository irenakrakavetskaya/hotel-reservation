<?php

declare(strict_types=1);

namespace App\Domain\User\Controller;

use App\Domain\User\Dto\UpdateUserRolesRequest;
use App\Domain\User\Dto\UserResponse;
use App\Domain\User\Entity\User;
use App\Domain\User\Security\UserManagementVoter;
use App\Domain\User\Service\UserRoleService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/v1/admin/users')]
#[IsGranted(UserManagementVoter::MANAGE)]
final class UserAdminController extends AbstractController
{
    private const MAX_PAGE_SIZE = 100;

    public function __construct(private readonly UserRoleService $roles) {}

    #[Route('', name: 'admin_user_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $search = $request->query->get('search');
        $limit = max(1, min(self::MAX_PAGE_SIZE, $request->query->getInt('limit', 50)));
        $offset = max(0, $request->query->getInt('offset', 0));

        $users = $this->roles->list(is_string($search) && '' !== trim($search) ? trim($search) : null, $limit, $offset);

        return $this->json(array_map(UserResponse::fromEntity(...), $users));
    }

    #[Route('/{id<\d+>}/roles', name: 'admin_user_roles', methods: ['PUT'])]
    public function updateRoles(int $id, #[MapRequestPayload] UpdateUserRolesRequest $payload, #[CurrentUser] User $actor): JsonResponse
    {
        return $this->json(UserResponse::fromEntity($this->roles->updateRoles($id, $payload->roles, $actor)));
    }
}
