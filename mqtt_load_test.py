import json
import time
import threading
import queue
import socket
import random
from concurrent.futures import ThreadPoolExecutor
from paho.mqtt import client as mqtt_client

MQTT_BROKER = "109.199.112.65"
MQTT_PORT = 1883

response_counters = {"ping": 0, "activation": 0, "order": 0}
counter_lock = threading.Lock()

def update_counter(response_type, u_id):
    with counter_lock:
        response_counters[response_type] += 1
        print(f"Device {u_id} responded to {response_type}. Total {response_type} responses: {response_counters[response_type]}")

def simulate_device(u_id, connect_timeout=10):
    client_id = f'egfollow_{u_id}'
    client = mqtt_client.Client(client_id=client_id, clean_session=True)

    def on_connect(client, userdata, flags, rc):
        if rc == 0:
            print(f"Device {u_id} connected to MQTT Broker!")
            client.subscribe(f"orders/{u_id}", qos=1)
            client.subscribe("devices/activation/req", qos=1)
            client.subscribe("order/ping/req", qos=1)
        else:
            print(f"Device {u_id} failed to connect, return code {rc}. Retrying...")
            time.sleep(5)
            client.reconnect()

    def on_message(client, userdata, msg):
        topic = msg.topic
        payload = msg.payload.decode()
        try:
            json_data = json.loads(payload)
            if json_data.get('request') == 'ping':
                response = {"device_id": u_id, "status": "active"}
                client.publish("devices/activation/v2/res", json.dumps(response), qos=1)
                update_counter("ping", u_id)
                return
            if 'activation' in json_data:
                response = {
                    "order_id": json_data.get('order_id'),
                    "user_id": u_id,
                    "type": json_data.get('type'),
                    "activation": json_data.get('activation')
                }
                client.publish("order/ping/res", json.dumps(response), qos=1)
                update_counter("activation", u_id)
                return
            if 'url' in json_data:
                order_id = json_data.get('order_id')
                if order_id:
                    response_topic = f"order/res/{order_id}/{u_id}"
                    response = {"status": "done"}
                    client.publish(response_topic, json.dumps(response), qos=1)
                    update_counter("order", u_id)
                return
        except json.JSONDecodeError:
            print(f"Device {u_id} invalid JSON: {payload}")

    client.on_connect = on_connect
    client.on_message = on_message

    # Set a shorter connection timeout
    client.connect_timeout = connect_timeout
    try:
        # Attempt connection with exponential backoff
        for attempt in range(3):
            try:
                client.connect(MQTT_BROKER, MQTT_PORT, keepalive=60)
                client.loop_start()
                return client
            except (socket.error, Exception) as e:
                print(f"Device {u_id} failed to connect (attempt {attempt + 1}): {e}")
                if attempt < 2:
                    time.sleep(random.uniform(1, 5))  # Random backoff
                else:
                    return None
    except Exception as e:
        print(f"Device {u_id} failed to connect: {e}")
        return None

def connect_batch(batch_ids, max_workers=50):
    clients = []
    with ThreadPoolExecutor(max_workers=max_workers) as executor:
        future_to_client = {executor.submit(simulate_device, str(u_id)): u_id for u_id in batch_ids}
        for future in future_to_client:
            client = future.result()
            if client:
                clients.append(client)
    return clients

def main(start_id, end_id):
    clients = []
    total_devices = end_id - start_id + 1
    print(f"Starting simulation for {total_devices} devices (IDs {start_id} to {end_id})")

    # Increase ephemeral port range and TCP settings (Windows-specific, run as admin)
    try:
        import subprocess
        subprocess.run('netsh int ipv4 set dynamicport tcp start=10000 num=50000', shell=True)
        print("Increased ephemeral port range.")
    except Exception as e:
        print(f"Failed to adjust TCP settings: {e}")

    # Batch connections with parallel execution
    batch_size = 100
    for batch_start in range(start_id, end_id + 1, batch_size):
        batch_end = min(batch_start + batch_size - 1, end_id)
        batch_ids = range(batch_start, batch_end + 1)
        print(f"Connecting batch {batch_start} to {batch_end}...")
        batch_clients = connect_batch(batch_ids)
        clients.extend(batch_clients)
        print(f"Batch {batch_start} to {batch_end} connected. {len(batch_clients)} successful connections.")
        time.sleep(2)  # Reduced delay between batches

    # Keep the main thread alive
    try:
        while True:
            time.sleep(1)
    except KeyboardInterrupt:
        print("Stopping simulation...")
        for client in clients:
            client.loop_stop()
            client.disconnect()

    print("\nFinal Response Counts:")
    print(f"Ping responses: {response_counters['ping']} / {total_devices}")
    print(f"Activation responses: {response_counters['activation']} / {total_devices}")
    print(f"Order responses: {response_counters['order']} / {total_devices}")

if __name__ == "__main__":
    start_id = 15648
    end_id = 16648  # 10,000 devices
    main(start_id, end_id)
