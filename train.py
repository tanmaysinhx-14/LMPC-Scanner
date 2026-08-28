from ultralytics import YOLO

def train_model():
    model = YOLO("yolo26n.pt")

    results = model.train(
        data="packaged-commodity-dataset/data.yaml",
        epochs=100,          # Increased for the larger dataset
        patience=20,         # Early stopping if mAP doesn't improve for 20 epochs
        imgsz=720,
        batch=-1,            # Maintains safe VRAM utilization (AutoBatch)
        device=0,
        workers=4,
        project="runs/detect",
        name="first",
        mosaic=1.0,
        fliplr=0.0,
        degrees=0.0,
        shear=0.0,
        hsv_h=0.0,
        hsv_s=0.0,
        hsv_v=0.0
    )

if __name__ == "__main__":
    train_model()