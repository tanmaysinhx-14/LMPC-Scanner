# FastAPI appfrom fastapi import FastAPI, UploadFile, File
from inference import run_inference
from image_check import check_manipulation
import io

app = FastAPI()

@app.post("/analyze")
async def analyze_image(file: UploadFile = File(...)):
    content = await file.read()
    
    # Step 1: Check image authenticity
    manipulation = check_manipulation(content)
    
    # Step 2: Run YOLO inference
    results = run_inference(content)
    
    return {
        "category":        results["class_name"],
        "severity":        results["severity"],   # 1–5
        "confidence":      results["confidence"],
        "bbox":            results["bbox"],
        "is_manipulated":  manipulation["flagged"],
        "manipulation_reason": manipulation["reason"]
    }