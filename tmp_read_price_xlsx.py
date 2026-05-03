import openpyxl, json
p=r"C:/Users/aodrl/Downloads/상품가 결정.xlsx"
wb=openpyxl.load_workbook(p,data_only=False)
ws=wb.active
out=[]
for r in range(1,25):
    row=[]
    for c in range(1,8):
        v=ws.cell(r,c).value
        row.append(v)
    out.append(row)
print(json.dumps(out,ensure_ascii=False))
