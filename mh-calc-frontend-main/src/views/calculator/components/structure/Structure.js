'use client';
import React, { useState, useEffect, useRef } from "react";
import dynamic from 'next/dynamic';
import { creatTreeStructure, getNodeSize } from "../initData";
const Tree = dynamic(() => import('react-d3-tree'), { ssr: false });
import NodeLabel from '../node/Node';
import css from './Structure.module.scss';
import AddNode from "../addNode/AddNode";
import { useWindowDimensions } from "@/common/utils/windowDimensions";
import Information from "../information/Information";
import '@/project/styles/tree.css';

const Structure = ({
    calculatorOwner = {},
    treeData,
    setRootStructure,
    canStructureEdit,
    deleteNode,
    packages,
    widthTree,
    position,
    setPosition,
    zoom
}) => {
    const [tree, setTree] = useState(false);
    const windowDimensions = useWindowDimensions();

    // ADD Node varible start
    const [showAddModal, setShowAddModal] = useState(false);
    const [addNode, setAddNode] = useState(false);
    // ADD Node varible end

    // information node start
    const [nodeInformationId, setNodeInformationId] = useState(false);
    // information node end

    useEffect(() => {
        if (widthTree?.current) {
            const x = widthTree.current.getBoundingClientRect().width / 2;
            const y = 200;

            setPosition({ x, y });
        }
    }, [widthTree]);

    useEffect(() => {
        if (treeData && typeof treeData === 'object' && !Array.isArray(treeData)) {
            const data = treeData ? [treeData].map(creatTreeStructure) : false;
            setTree(data);
        }
    }, [treeData]);

    const nodeSize = getNodeSize(windowDimensions);

    const renderForeignObjectNode = ({ nodeDatum, toggleNode, hierarchyPointNode }) => {
        return (
            <NodeLabel
                nodeDatum={nodeDatum}
                toggleNode={toggleNode}
                hierarchyPointNode={hierarchyPointNode}
                setAddNode={setAddNode}
                setShowAddModal={setShowAddModal}
                canStructureEdit={canStructureEdit}
                deleteNode={deleteNode}
                packages={packages}
                setInformationId={setNodeInformationId}
                calculatorOwner={calculatorOwner || {}}
            />
        );
    };
   
    // FIX ZOOM
    useEffect(() => {
        const preventZoom = (e) => {
            if (e.ctrlKey || e.metaKey || e.deltaY) {
                e.preventDefault();
            }
        };

        const preventKeyZoom = (e) => {
            if ((e.ctrlKey || e.metaKey) && (e.key === '+' || e.key === '-' || e.key === '=')) {
                e.preventDefault();
            }
        };

        const element = widthTree?.current;

        if (element) {
            element.addEventListener('wheel', preventZoom, { passive: false });
            element.addEventListener('keydown', preventKeyZoom);
        };

        // Убираем обработчики при размонтировании компонента
        return () => {
            if (element) {
                element.removeEventListener('wheel', preventZoom);
                element.removeEventListener('keydown', preventKeyZoom);
            }
        };
    }, [widthTree]);

    return (
        <>
            <div className={css.wrapper} ref={widthTree}>
                {tree ? (
                    <Tree
                        data={tree}
                        translate={position}
                        pathFunc='step'
                        nodeSize={nodeSize}
                        rootNodeClassName='node__root'
                        branchNodeClassName='node__branch'
                        leafNodeClassName='node__leaf'
                        transitionDuration={2000}
                        orientation='vertical'
                        separation={{ siblings: 1.5, nonSiblings: 2 }}
                        renderCustomNodeElement={(rd3tProps) => {
                            return renderForeignObjectNode({
                                ...rd3tProps,
                            });
                        }}
                        zoom={zoom}
                    />
                ) : null}
            </div>
            <AddNode
                isOpen={showAddModal}
                onClose={setShowAddModal}
                node={addNode}
                setNode={setAddNode}
                setRootStructure={setRootStructure}
                packages={packages}
            />
            <Information
                id={nodeInformationId}
                setId={setNodeInformationId}
            />
        </>
    );
};

export default Structure;