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
final class ReportController extends AbstractController
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly ReportFileStorage $storage,
    ) {}

    #[Route('', name: 'report_create', methods: ['POST'])]
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
    public function list(): JsonResponse
    {
        return $this->json(ReportResponse::fromEntities($this->reports->listRecent()));
    }

    #[Route('/{id}', name: 'report_get', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        return $this->json(ReportResponse::fromEntity($this->reports->get($this->parseId($id))));
    }

    #[Route('/{id}/download', name: 'report_download', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
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
