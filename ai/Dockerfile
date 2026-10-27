FROM python:3.11-slim-bookworm AS models
WORKDIR /build
RUN pip install --no-cache-dir onnx==1.19.1
COPY ai/fetch_model.py ai/normalize_model.py ./
COPY ai/manifests/nanodet-plus-m-416.json ./manifests/nanodet-plus-m-416.json
RUN python fetch_model.py manifests/nanodet-plus-m-416.json /models && python normalize_model.py /models/model.json

FROM python:3.11-slim-bookworm AS cpu
ENV PYTHONUNBUFFERED=1 OMP_NUM_THREADS=1 OPENBLAS_NUM_THREADS=1
WORKDIR /opt/ai
COPY ai/requirements.txt ./
RUN pip install --no-cache-dir -r requirements.txt
COPY ai/detector.py ai/server.py ./
COPY ai/licenses ./licenses
COPY --from=models /models /models
USER 65532:65532
HEALTHCHECK --interval=30s --timeout=3s CMD python -c "import urllib.request; urllib.request.urlopen('http://127.0.0.1:8091/health', timeout=2)"
CMD ["python", "server.py"]

FROM nvidia/cuda:12.4.1-cudnn-runtime-ubuntu22.04 AS cuda
ENV PYTHONUNBUFFERED=1 OMP_NUM_THREADS=1 OPENBLAS_NUM_THREADS=1 AI_DEVICE=cuda
WORKDIR /opt/ai
RUN apt-get update && apt-get install -y --no-install-recommends python3 python3-pip ca-certificates && rm -rf /var/lib/apt/lists/*
RUN pip3 install --no-cache-dir numpy==2.2.6 opencv-python-headless==4.12.0.88 onnxruntime-gpu==1.23.2
COPY ai/detector.py ai/server.py ./
COPY ai/licenses ./licenses
COPY --from=models /models /models
USER 65532:65532
HEALTHCHECK --interval=30s --timeout=3s CMD python3 -c "import urllib.request; urllib.request.urlopen('http://127.0.0.1:8091/health', timeout=2)"
CMD ["python3", "server.py"]
