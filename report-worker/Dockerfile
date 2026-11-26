FROM golang:1.25-alpine AS build
WORKDIR /src
COPY go.mod go.sum ./
RUN go mod download
COPY . .
RUN go test ./... && CGO_ENABLED=0 go build -trimpath -ldflags="-s -w" -o /out/report-worker ./cmd/worker

FROM alpine:3.22
# Share the PHP-FPM www-data group for read-only access to generated PDFs.
RUN adduser -S -G www-data reports \
    && mkdir -p /data/reports \
    && chown reports:www-data /data/reports
COPY --from=build /out/report-worker /usr/local/bin/report-worker
USER reports
ENV REPORTS_DIR=/data/reports
EXPOSE 8081
ENTRYPOINT ["/usr/local/bin/report-worker"]
