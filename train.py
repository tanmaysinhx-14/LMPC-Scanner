
from ultralytics import YOLO

if __name__ == '__main__':
    # Load pretrained YOLOv8 nano model
    model = YOLO('yolov8n.pt')

    # Train
    model.train(
        data=r"C:\Users\Srinjan Sengupta\OneDrive\Desktop\SIH photos\Fallen Tree.v1i.yolov8\data.yaml",
        epochs=100,
        imgsz=640,
        batch=16,
        device=0,           # GPU
        workers=8,
        lr0=0.01,
        patience=10,        # stops early if no improvement for 10 epochs
        save=True,
        project='runs/train',
        name='civic5_v1'
    )