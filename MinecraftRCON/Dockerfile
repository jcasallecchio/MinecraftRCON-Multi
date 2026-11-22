FROM debian:bookworm-slim AS seed-builder

ARG CUBIOMES_COMMIT=294c72ab0392dd818a91d293dbd5774d4874d64b
COPY seed_tile.c /tmp/seed_tile.c
RUN apt-get update && apt-get install -y --no-install-recommends ca-certificates curl gcc make tar binutils libc6-dev && \
    mkdir -p /tmp/cubiomes && \
    curl --fail --location --retry 3 "https://github.com/FragrantResult186/cubiomes-bedrock/archive/${CUBIOMES_COMMIT}.tar.gz" -o /tmp/cubiomes.tar.gz && \
    tar -xzf /tmp/cubiomes.tar.gz -C /tmp/cubiomes --strip-components=1 && \
    make -C /tmp/cubiomes -j2 && \
    gcc -O2 -Wall -Wextra -fwrapv -I/tmp/cubiomes /tmp/seed_tile.c /tmp/cubiomes/libcubiomes.a -lm -pthread -o /usr/local/bin/minedev-seed-tile && \
    cp /tmp/cubiomes/LICENSE /tmp/CUBIOMES_LICENSE.txt && \
    strip /usr/local/bin/minedev-seed-tile && \
    rm -rf /var/lib/apt/lists/* /tmp/cubiomes /tmp/cubiomes.tar.gz /tmp/seed_tile.c

FROM python:3.11-slim

RUN apt-get update && apt-get install -y --no-install-recommends \
    unzip curl screen psmisc ca-certificates && \
    apt-get clean && rm -rf /var/lib/apt/lists/* && \
    mkdir -p /usr/share/licenses/cubiomes-bedrock

COPY requirements.txt /requirements.txt
RUN python -m pip install --no-cache-dir -r /requirements.txt

COPY rcon_server.py /rcon_server.py
COPY backup_manager.py /backup_manager.py
COPY update_transaction.py /update_transaction.py
COPY seed_tile_worker.py /seed_tile_worker.py
COPY --from=seed-builder /usr/local/bin/minedev-seed-tile /usr/local/bin/minedev-seed-tile
COPY --from=seed-builder /tmp/CUBIOMES_LICENSE.txt /usr/share/licenses/cubiomes-bedrock/LICENSE
COPY run.sh /run.sh
COPY instance.sh /instance.sh
RUN chmod +x /run.sh /instance.sh

WORKDIR /share/minecraftRCON-Multi

CMD ["/run.sh"]
