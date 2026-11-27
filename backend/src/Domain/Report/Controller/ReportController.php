<?php

declare(strict_types=1);

namespace App\Domain\Report\Controller;

use App\Domain\Report\Dto\CreateReportRequest;
use App\Domain\Report\Dto\ReportResponse;
use App\Domain\Report\Entity\ReportStatus;
use App\Domain\Report\Exception\ReportNotReadyException;
use App\Domain\Report\Service\ReportFileStorage;
use App\Domain\Report\Service\ReportService;
use App\Domain\User\Security\AppUserInterface;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Uid\Uuid;

#[Route('/v1/admin/reports')]
#[IsGranted('ROLE_STAFF')]
#[OA\Tag(name: 'Reports')]
#[OA\Response(response: 401, description: 'Authentication required')]
#[OA\Response(response: 403, description: 'Staff access required')]
final class ReportController extends AbstractController
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly ReportFileStorage $storage,
    ) {}

    #[Route('', name: 'report_create', methods: ['POST'])]
    #[OA\Post(description: 'Inclusive UTC calendar dates, at most one year; to cannot be in the future.', security: [['Bearer' => []]])]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(required: ['from', 'to'], properties: [
        new OA\Property(property: 'from', type: 'string', format: 'date'),
        new OA\Property(property: 'to', type: 'string', format: 'date'),
    ]))]
    #[OA\Response(response: 201, description: 'Report queued', headers: [new OA\Header(header: 'Location', schema: new OA\Schema(type: 'string'))], content: new Model(type: ReportResponse::class))]
    #[OA\Response(response: 422, description: 'Invalid request; JSON problem details when requested', content: new OA\JsonContent(ref: '#/components/schemas/HttpProblem'))]
    public function create(
        #[MapRequestPayload]
        CreateReportRequest $request,
        #[CurrentUser]
        AppUserInterface $user,
    ): JsonResponse {
        $report = $this->reports->create($user->getId(), $request->fromDate(), $request->toDate());

        return $this->json(
            ReportResponse::fromEntity($report),
            Response::HTTP_CREATED,
            ['Location' => $this->generateUrl('report_get', ['id' => $report->getId()->toRfc4122()])],
        );
    }

    #[Route('', name: 'report_list', methods: ['GET'])]
    #[OA\Get(security: [['Bearer' => []]])]
    #[OA\Response(response: 200, description: 'Recent reports', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: new Model(type: ReportResponse::class))))]
    public function list(): JsonResponse
    {
        return $this->json(ReportResponse::fromEntities($this->reports->listRecent()));
    }

    #[Route('/{id}', name: 'report_get', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    #[OA\Get(security: [['Bearer' => []]])]
    #[OA\Response(response: 200, description: 'Report', content: new Model(type: ReportResponse::class))]
    #[OA\Response(response: 404, description: 'Report not found', content: new OA\JsonContent(ref: '#/components/schemas/DomainError'))]
    #[OA\Response(response: 422, description: 'Invalid report ID; JSON problem details when requested', content: new OA\JsonContent(ref: '#/components/schemas/HttpProblem'))]
    public function get(string $id): JsonResponse
    {
        return $this->json(ReportResponse::fromEntity($this->reports->get($this->parseId($id))));
    }

    #[Route('/{id}/download', name: 'report_download', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    #[OA\Get(security: [['Bearer' => []]])]
    #[OA\Response(response: 200, description: 'PDF report', content: new OA\MediaType(mediaType: 'application/pdf', schema: new OA\Schema(type: 'string', format: 'binary')))]
    #[OA\Response(response: 404, description: 'Report not found', content: new OA\JsonContent(ref: '#/components/schemas/DomainError'))]
    #[OA\Response(response: 409, description: 'Report not ready', content: new OA\JsonContent(ref: '#/components/schemas/DomainError'))]
    #[OA\Response(response: 422, description: 'Invalid report ID; JSON problem details when requested', content: new OA\JsonContent(ref: '#/components/schemas/HttpProblem'))]
    public function download(string $id): Response
    {
        $report = $this->reports->get($this->parseId($id));
        if (ReportStatus::Done !== $report->getStatus()) {
            throw ReportNotReadyException::forId($report->getId()->toRfc4122());
        }

        $filename = sprintf('reservations-report-%s.pdf', $report->getId()->toRfc4122());
        $response = $this->file($this->storage->pathFor($report), $filename, ResponseHeaderBag::DISPOSITION_ATTACHMENT);
        $response->headers->set('Content-Type', 'application/pdf');

        return $response;
    }

    private function parseId(string $id): Uuid
    {
        if (!Uuid::isValid($id)) {
            throw new \Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException('Invalid report id.');
        }

        return Uuid::fromString($id);
    }
}
