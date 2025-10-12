import json
import time
import threading
from paho.mqtt import client as mqtt_client

# MQTT Server details from the provided code
MQTT_BROKER = "109.199.112.65"
MQTT_PORT = 1883

# Counters for tracking responses
response_counters = {
    "ping": 0,
    "activation": 0,
    "order": 0
}

# Lock for thread-safe counter updates
counter_lock = threading.Lock()


# Function to update and print response counters
def update_counter(response_type, u_id):
    with counter_lock:
        response_counters[response_type] += 1
        print(
            f"Device {u_id} responded to {response_type}. Total {response_type} responses: {response_counters[response_type]}")


# Function to handle a single client (simulating a user device)
def simulate_device(u_id):
    client_id = f'egfollow_{u_id}'
    client = mqtt_client.Client(client_id=client_id, clean_session=True)

    # Callback for when the client connects
    def on_connect(client, userdata, flags, rc):
        if rc == 0:
            print(f"Device {u_id} connected to MQTT Broker!")
            # Subscribe to relevant topics as per the code
            client.subscribe(f"orders/{u_id}", qos=1)
            client.subscribe("devices/activation/req", qos=1)
            client.subscribe("order/ping/req", qos=1)
        else:
            print(f"Device {u_id} failed to connect, return code {rc}")

    # Callback for when a message is received
    def on_message(client, userdata, msg):
        topic = msg.topic
        payload = msg.payload.decode()
        #print(f"Device {u_id} received message on {topic}: {payload}")

        try:
            json_data = json.loads(payload)

            # Handle ping request
            if json_data.get('request') == 'ping':
                response = {"device_id": u_id, "status": "active"}
                client.publish("devices/activation/v2/res", json.dumps(response), qos=1)
                #print(f"Device {u_id} sent ping response")
                update_counter("ping", u_id)
                return

            # Handle activation echo
            if 'activation' in json_data:
                response = {
                    "order_id": json_data.get('order_id'),
                    "user_id": u_id,
                    "type": json_data.get('type'),
                    "activation": json_data.get('activation')
                }
                client.publish("order/ping/res", json.dumps(response), qos=1)
                #print(f"Device {u_id} sent activation response")
                update_counter("activation", u_id)
                return

            # Handle orders (follow/like) - Simulate immediate success without any actual automation
            if 'url' in json_data:
                order_id = json_data.get('order_id')
                if order_id:
                    # Send immediate 'done' response
                    response_topic = f"order/res/{order_id}/{u_id}"
                    response = {"status": "done"}
                    client.publish(response_topic, json.dumps(response), qos=1)
                    #print(f"Device {u_id} simulated order {order_id} and sent 'done' response")
                    update_counter("order", u_id)
                return

        except json.JSONDecodeError:
            print(f"Device {u_id} invalid JSON: {payload}")

    client.on_connect = on_connect
    client.on_message = on_message

    # Connect to broker
    try:
        client.connect(MQTT_BROKER, MQTT_PORT, keepalive=60)
    except Exception as e:
        print(f"Device {u_id} failed to connect: {e}")
        return

    # Start the loop
    client.loop_forever()


# Main function to start multiple devices in a range
def main(start_id, end_id):
    threads = []
    total_devices = end_id - start_id + 1
    print(f"Starting simulation for {total_devices} devices (IDs {start_id} to {end_id})")

    for u_id in range(start_id, end_id + 1):
        thread = threading.Thread(target=simulate_device, args=(str(u_id),))
        threads.append(thread)
        thread.start()
        time.sleep(0.1)  # Small delay to avoid connection flood

    # Wait for all threads
    for thread in threads:
        thread.join()

    # Print final response counts
    print("\nFinal Response Counts:")
    print(f"Ping responses: {response_counters['ping']} / {total_devices}")
    print(f"Activation responses: {response_counters['activation']} / {total_devices}")
    print(f"Order responses: {response_counters['order']} / {total_devices}")


# Example: Simulate devices with uIds from 5000 to 5100
if __name__ == "__main__":
    start_id = 15648  # Your start ID
    end_id = 25647  # Your end ID
    main(start_id, end_id)
#end_id = 15634
