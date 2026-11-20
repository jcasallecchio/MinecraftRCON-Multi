FROM python:3.11-slim

RUN apt-get update && apt-get install -y --no-install-recommends \
    unzip curl screen psmisc ca-certificates && \
    apt-get clean && rm -rf /var/lib/apt/lists/*

COPY requirements.txt /requirements.txt
RUN python -m pip install --no-cache-dir -r /requirements.txt

COPY rcon_server.py /rcon_server.py
COPY backup_manager.py /backup_manager.py
COPY update_transaction.py /update_transaction.py
COPY run.sh /run.sh
COPY instance.sh /instance.sh
RUN chmod +x /run.sh /instance.sh

WORKDIR /share/minecraftRCON-Multi

CMD ["/run.sh"]
