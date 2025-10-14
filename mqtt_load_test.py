import json
import time
import threading
from paho.mqtt import client as mqtt_client

MQTT_BROKER = "109.199.112.65"
MQTT_PORT = 1883

response_counters = {"ping": 0, "activation": 0, "order": 0}
counter_lock = threading.Lock()

def update_counter(response_type, u_id):
    with counter_lock:
        response_counters[response_type] += 1
        print(f"Device {u_id} responded to {response_type}. Total {response_type} responses: {response_counters[response_type]}")

def simulate_device(u_id):
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

    try:
        # أضف المصادقة لو مطلوبة
        # client.username_pw_set("username", "password")
        client.connect(MQTT_BROKER, MQTT_PORT, keepalive=60)
        client.loop_start()  # تشغيل الـ loop في الخلفية
        return client
    except Exception as e:
        print(f"Device {u_id} failed to connect: {e}")
        return None

def main(start_id, end_id):
    clients = []
    total_devices = end_id - start_id + 1
    print(f"Starting simulation for {total_devices} devices (IDs {start_id} to {end_id})")

    # Batch connections to avoid overwhelming the broker
    batch_size = 100  # عدد الأجهزة في كل دفعة
    for batch_start in range(start_id, end_id + 1, batch_size):
        batch_end = min(batch_start + batch_size - 1, end_id)
        for u_id in range(batch_start, batch_end + 1):
            client = simulate_device(str(u_id))
            if client:
                clients.append(client)
            time.sleep(0.5)  # تأخير نص ثانية بين كل جهاز
        print(f"Batch {batch_start} to {batch_end} connected. Waiting before next batch...")
        time.sleep(10)  # تأخير 10 ثواني بين كل دفعة

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
    end_id = 18647  # 1000 devices
    main(start_id, end_id)
